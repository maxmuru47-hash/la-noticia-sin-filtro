# Modelo de seguridad — CrediSan Control

Orden de prioridades del sistema: **seguridad > integridad > simplicidad >
exactitud > experiencia**. Cuando dos chocan, gana la de la izquierda.

---

## 1. Tres superficies, tres niveles de confianza

| Superficie | Se autentica con | Puede tocar |
|---|---|---|
| Terminal (kiosco) | `device_token` + `terminal_code` | **Nada.** Sólo llama a la Edge Function |
| Panel (CEO/Admin/Jefe) | Supabase Auth (correo + contraseña) | Tablas, filtradas por RLS |
| Backend (Edge Functions) | `service_role` | Todo, y queda auditado |

**El terminal no es un usuario de Supabase.** Un teléfono en un mostrador es un
dispositivo hostil: cualquiera que lo desbloquee tiene el navegador. Si llevara
un JWT con permiso de lectura, esa persona podría listar el personal de la
sede desde la consola. Con `device_token` + Edge Function sólo puede hacer una
cosa: *pedir que se registre una marcación*.

## 2. Alcance por sede

Una sola función decide todo:

```sql
app.can_see_branch(b) = ceo ó b = sede_del_usuario
```

Se usa en cada política. Rol y sede salen del JWT (hook de claims) y, si el
hook no está activo, de `profiles` mediante una función `SECURITY DEFINER`
—que evita la recursión clásica de RLS sobre `profiles`.

Cambiar la regla de alcance de todo el sistema es cambiar esa función.

## 3. El salario vive en otra tabla

PostgreSQL **no tiene RLS por columna**. Si `salario` estuviera en `employees`,
el jefe operativo —que necesita leer `employees`— lo vería. Por eso está en
`employee_compensation`, donde el rol `supervisor` no tiene ninguna política:
la tabla le devuelve cero filas, consulte como consulte.

Verificado en `01_suite.sql` §7.

## 4. El PIN

Seis dígitos son un millón de combinaciones. Con cuarenta trabajadores hay un
PIN válido cada veinticinco mil intentos. Sin defensas, un terminal desatendido
cae en una noche. Cuatro capas:

1. **Nunca en claro, nunca comparado en el cliente.** El PIN sale del teclado,
   viaja por HTTPS a la Edge Function y muere ahí.
2. **`bcrypt(pin || pepper)`**, coste 10. El *pepper* vive en el entorno de la
   Edge Function, **no en la base de datos**. Un volcado robado de PostgreSQL
   no permite recuperar ni verificar ningún PIN.
3. **Índice ciego `HMAC-SHA256(pin, pepper)`** con restricción `UNIQUE`. bcrypt
   lleva sal y no se puede indexar; sin índice habría que probar bcrypt contra
   los N trabajadores. El índice ciego da búsqueda en O(1) **y** garantiza que
   dos trabajadores nunca compartan PIN. Localiza, no autentica: autenticar es
   siempre bcrypt.
4. **Freno progresivo por terminal, sin bloqueo.** El requisito es explícito:
   el terminal es compartido y **un trabajador no puede dejar sin marcar a sus
   compañeros**. Cada fallo reciente duplica la espera (300 ms → tope 3 s) y a
   los doce fallos en diez minutos se levanta una alerta en `audit_logs`.
   Nunca se deniega el servicio.

**El PIN es de un solo trabajador en toda la empresa** (índice ciego global) y
sólo sirve en el terminal de su propia sede: intentarlo en otra deja una
novedad `marcacion_foranea` para revisión.

### Por qué `verify_pin` devuelve un motivo en vez de lanzar una excepción

Si lanzara, PostgreSQL revertiría en el mismo instante el registro del intento
fallido y la alerta de fuerza bruta: **el atacante borraría su propio rastro con
sólo fallar**. Devolviendo el resultado, el rechazo y su auditoría se confirman.
Lo mismo vale para el rechazo de un lote offline.

## 5. Las marcaciones no se editan

`attendance_events` no tiene políticas de `UPDATE` ni de `DELETE`, y `authenticated`
no tiene esos permisos. **Ni el CEO puede reescribir una hora desde la API.**
El valor probatorio del sistema depende exactamente de eso.

Corregir es insertar en `attendance_corrections`: valor original, valor nuevo,
usuario, fecha y motivo obligatorio de diez caracteres. Los informes muestran la
corrección vigente; el original permanece intacto.

Igual con `audit_logs`: sólo lectura, para todos.

## 6. Offline sin agujero

El riesgo evidente: cambiar la hora del Android, marcar y aparecer puntual.
Cinco defensas:

1. **El PIN offline se cifra con clave pública** (sealed box). El terminal
   guarda sólo la pública: cifra lo que teclea el trabajador y no puede volver
   a leerlo. Nunca se envían hashes de PIN al dispositivo.
2. **Firma HMAC** de cada registro con la `signing_key` del terminal: editar la
   cola en IndexedDB invalida la firma.
3. **Contador monotónico `seq`** por terminal: repetirlo o retroceder es un
   reenvío y se rechaza. Un salto indica registros borrados y alerta.
4. **Reloj monotónico**: la hora offline se calcula con `performance.now()`
   —inmune a cambiar la hora del sistema— más el desfase medido en la última
   sincronización. Se guarda el desfase (`clock_drift_sec`).
5. **Ventana acotada**: nada del futuro, nada de más de 72 h, nada anterior a la
   última sincronización confirmada.

Y todo evento offline queda marcado (`origin = 'offline'`). Si el desfase supera
el umbral, se abre una novedad `desfase_reloj` para revisión: **la marcación no
se descarta, se señala**.

Consecuencia honesta: durante un corte, el kiosco **no puede** confirmar la
identidad ni mostrar el nombre. Dice «Registro recibido, pendiente de
validación». La alternativa —cachear credenciales en el dispositivo— entrega el
millón de combinaciones a quien tenga el teléfono.

## 7. La fotografía

- Depósito **privado**, sin políticas para `authenticated`: **ni el CEO lo lee
  directamente**. La lectura pasa por la Edge Function `evidencia-url`, que
  valida rol y sede, emite una URL firmada de 60 s y deja registro de quién vio
  qué cara y cuándo.
- Que falte la foto **nunca impide marcar**. Se registra `sin_evidencia` y se
  abre una novedad automática; la reincidencia es visible para Administración.
- Retención de 180 días (configurable). Al vencer se borra **sólo el archivo**:
  la marcación, su hora, su clasificación y su auditoría permanecen.

## 8. Lo que nunca sale por la API

Columnas sin permiso de lectura para nadie, tampoco para el CEO:

- `employees.pin_hash`, `employees.pin_lookup`
- `terminals.token_hash`, `terminals.signing_key`
- `terminal_pairing_codes.code_hash`
- la tabla `punch_tickets` completa

Y `authenticated` no puede ejecutar ninguna función del motor: `verify_pin`,
`register_punch`, `set_employee_pin`, `redeem_pairing_code`, `attach_evidence`.
Sólo `service_role`. Verificado en `01_suite.sql` §15.

## 9. Auditoría

Un disparador `SECURITY DEFINER` registra alta, cambio y baja en trece tablas,
con actor, entidad, `before`, `after` y fecha. Nunca copia secretos ni columnas
de puro latido (última conexión, contadores). Un `UPDATE` que no cambió nada no
se audita.

## 10. Lo que este diseño *no* resuelve

Dicho para que nadie se confíe:

- **Un trabajador puede marcar por otro si conoce su PIN.** La foto es la
  disuasión y la prueba, no un control biométrico. Si hace falta certeza, el
  paso siguiente es huella o reconocimiento facial, y es otra decisión.
- **Revocar un rol no invalida el JWT ya emitido** hasta que expire (30 min).
  `profiles.is_active = false` bloquea la renovación.
- **La `service_role key` anula toda la seguridad.** Vive sólo en secretos de
  Edge Functions. Si se filtra, hay que rotarla de inmediato.
- **El pepper del PIN no se puede recuperar.** Perderlo obliga a regenerar
  todos los PIN.
