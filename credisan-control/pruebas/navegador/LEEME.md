# Pruebas de navegador

Estas pruebas abren el panel y el kiosco en un **Chromium de verdad** contra un
servidor Supabase simulado. No sustituyen a las baterías SQL de
`supabase/tests/` —que comprueban la seguridad donde de verdad vive, en la base
de datos— sino que verifican lo otro: que la pantalla pinta lo que debe, que no
enseña lo que no debe, y que no hay errores de JavaScript.

Han pagado su precio más de una vez. Encontraron que el atributo `hidden` no
ocultaba la pantalla de acceso porque `display:grid` lo anulaba; que
`generarPin` usaba `config` sin importarlo y el `try/catch` lo disfrazaba de
«no se pudo conectar»; y que el selector de sede seguía puesto en el
comparativo del período, donde no filtra nada.

## Cómo correrlas

Hace falta Node y Playwright con Chromium.

```bash
cd credisan-control/public && python3 -m http.server 8099 &
cd ../pruebas/navegador
npm install playwright        # la primera vez
node panel-f4-test.js
node panel-f5-test.js
node panel-f6-test.js
node kiosk-f6-test.js
```

Cada prueba termina con código 0 si todo pasa, y 1 si algo falla: sirve
para integración continua tal cual.

## Qué hay aquí

| Archivo | Qué comprueba |
|---|---|
| `mock-supabase.js` | Servidor simulado de las fases 2 y 3 |
| `mock-f4.js` | Servidor simulado de la Fase 4. Imita la respuesta **con las políticas ya aplicadas**: si el usuario es jefe de Maracaibo, Caja Seca sencillamente no existe en los datos que llegan |
| `panel-f3-test.js` | Terminales, código de vinculación y revelación del PIN |
| `panel-f4-test.js` | 52 comprobaciones sobre los tres roles: tablero del día, período, ranking, novedades y **aislamiento de datos entre sedes** |
| `mock-f5.js` | Servidor simulado de la Fase 5 |
| `panel-f5-test.js` | 47 comprobaciones: cierre semanal, auditoría legible, y la **descarga real del CSV** —que se abre y se comprueba que no lleva cédulas, ni salarios, ni gente de otra sede— |
| `kiosk-test.js` | El kiosco completo con cámara simulada, incluida la comprobación de que el PIN no vuelve a viajar al confirmar |
| `mock-f6.js` · `panel-f6-test.js` | La pantalla de conexión de los terminales, por rol |
| `kiosk-f6-test.js` | **El terminal sin conexión.** Corta la red de verdad, abre IndexedDB para comprobar que el PIN no quedó en claro, y descifra los sobres con una llave privada real generada en la propia prueba |
| `kiosk-foto-test.js` | **La fotografía del trabajador apresurado.** Confirma en cuanto aparece su nombre, sin esperar nada — que es lo que hace quien se sabe su PIN. De ahí salían casi todas las novedades de «marcación sin evidencia»: la cámara aún no daba imagen. Comprueba también que la espera tenga tope, que sin cámara se marque igual y sin demora, y que un PIN equivocado no deje el sensor encendido |
| `kiosk-vinculacion-test.js` | **Cuándo se pierde la vinculación, y cuándo NO.** Si el terminal se desvincula, en esa sede no marca nadie hasta que alguien con acceso al panel genere un código. Comprueba que eso pase sólo cuando la vinculación se perdió de verdad — y que un terminal simplemente APAGADO en el panel conserve la suya |
| `panel-visor-test.js` | **Por qué no se ve una fotografía.** «No se pudo cargar la fotografía» tapaba cuatro causas distintas —sin permiso, archivo ausente, enlace a otro servidor, descarga caída— y no distinguía ninguna. Comprueba que cada una tenga su nombre y que la única recuperable ofrezca reintentar |
| `panel-sesion-test.js` | **La sesión no se tira por un tropiezo.** Un fallo de red al arrancar, o un token a medio renovar, hacían que el panel BORRARA la sesión: de ahí venía el «se me cierra y tengo que poner la clave otra vez». Comprueba que se reintente, que la sesión sobreviva, y que un acceso desactivado sí se cierre |
| `panel-respaldo-test.js` | **Avisar cuando la base lleva días sin respaldo.** El respaldo estuvo cuatro noches fallando y se supo por casualidad. Comprueba que el panel lo diga —y, igual de importante, que se calle cuando todo va bien: un aviso que sale siempre deja de leerse |
| `panel-horarios-test.js` | **El horario propio de un trabajador.** 31 comprobaciones: que el botón esté en la ficha de la persona, que se mande al servidor exactamente lo marcado —con el almuerzo fuera cuando la jornada es corrida, que es justo lo que la base rechazaría— y que al jefe operativo y al socio el panel no les ofrezca un botón que la base les va a rechazar |
| `panel-documentos-test.js` | **El documento de una novedad.** 43 comprobaciones: que el archivo se suba ANTES de registrar la novedad —al revés la base la rechazaría—, que vaya a la carpeta de la sede del trabajador, que un PDF no se convierta en foto, que el panel no ofrezca *Aprobar* en lo que la base va a rechazar pero sí en lo que ya tiene su papel, y que abrir un reposo médico pase siempre por la función que deja rastro |

## Una advertencia sobre cómo escribirlas

La primera versión de la batería SQL de la Fase 4 buscaba el número `150` como
texto para asegurarse de que ningún salario se escapaba. Lo encontró dentro de
un UUID aleatorio (`…f860-4150-8e02…`) y falló sin que hubiera fuga alguna.
Pasaba o fallaba según la suerte del sorteo.

La lección vale también aquí: **no se busca lo que no debería estar, se exige
exactamente lo que sí debería**. La comprobación buena no es «que no aparezca
la palabra salario», es «que la ficha tenga estos campos y ni uno más».
