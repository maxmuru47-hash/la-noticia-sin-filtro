# Poner CrediSan Control en marcha

Cuatro pasos. Ninguno requiere saber programar.

---

## 1 · Actualizar la web (2 min)

Ya sabes el camino: Administrador de archivos → `public_html` → sube
`credisancontrolweb.zip` → clic derecho → **Extract** → nombre de carpeta
`control` → EXTRACT.

> Recuerda: el gestor **no extrae si la carpeta ya existe**. Borra primero
> `public_html/control` (o su contenido) y luego extrae. Es el paso que más
> cuesta la primera vez.

## 2 · Crear la base de datos (5 min)

1. Entra en <https://supabase.com> → **New project**
   - Nombre: `credisan-control`
   - Contraseña: genera una y **guárdala**
   - Región: `East US (North Virginia)`
2. Espera a que termine (unos 2 minutos).
3. Menú izquierdo → **SQL Editor** → **New query**.
4. Abre **`INSTALAR.sql`**, cópialo entero y pégalo ahí.
5. Pulsa **RUN**.

En unos segundos verás `Success`. Eso crea las 21 tablas, las 46 políticas de
seguridad, las 3 sedes, los 3 terminales, los horarios y la configuración.

> Si lo ejecutas dos veces te avisa y se detiene. No rompe nada.

## 3 · Conectar la web con la base (2 min)

1. Supabase → **Settings → API**. Copia:
   - **Project URL**
   - **anon public** (la clave larga)
2. Administrador de archivos → `control/config/env.js` → clic derecho → **Edit**.
3. Pega cada valor entre sus comillas:

```js
window.CREDISAN_ENV = {
  supabaseUrl:     'https://abcdefgh.supabase.co',
  supabaseAnonKey: 'eyJhbGciOiJIUzI1NiIs...',
  version:         '1.0.0'
};
```

4. Guarda y abre <https://control.sinfiltroconmax.com> con **Ctrl + F5**.
   Los cuatro indicadores deben quedar en verde.

> La clave `anon` es pública por diseño: está hecha para el navegador. La
> seguridad real vive en las políticas de la base de datos. La clave
> `service_role` **nunca** se pega aquí.

## 4 · Tu usuario (2 min)

1. Supabase → **Authentication → Users → Add user**
   - Correo: el tuyo
   - Contraseña: la que quieras
   - Marca **Auto Confirm User**
2. En **Authentication → Sign In / Providers**, desactiva
   **Allow new users to sign up**. Nadie se registra solo: los usuarios los
   creas tú.
3. Abre <https://control.sinfiltroconmax.com/panel/> y entra con ese correo.

La primera persona que entra queda registrada como **Dirección General**, con
acceso a las tres sedes. A partir de ahí esa puerta se cierra sola: el segundo
que entre sin permiso verá que no tiene acceso.

---

## Ya dentro: qué puedes hacer

**Sedes** — ver las tres con su número de trabajadores y terminales. Crear una
nueva: se crea con su horario estándar y su primer terminal, todo de una vez.

**Personal** — registrar trabajadores con cédula, cargo, fecha de ingreso y
salario base. Editarlos. El salario sólo lo ven dirección y administración; el
jefe operativo no lo ve, y no es que se le oculte el botón: la base de datos no
se lo entrega.

**Horarios** — los siete días de cada sede. Un interruptor por día. Apagado es
descanso y **no cuenta como ausencia**. Activar el sábado es mover ese
interruptor: sin desplegar nada, sin tocar código.

**Accesos** *(sólo dirección)* — dar entrada al panel a la administradora o al
jefe operativo de cada sede.

---

## Lo que todavía no está

El **PIN de marcación** y el **terminal** son la Fase 3. Por eso los
trabajadores aparecen con la etiqueta «PIN pendiente»: están registrados y con
horario, listos para marcar en cuanto exista el terminal.

## Si algo falla

| Lo que ves | Qué pasa |
|---|---|
| «Falta conectar el servidor» | `config/env.js` está vacío o mal pegado |
| «Correo o contraseña incorrectos» | Revisa el usuario en Authentication → Users |
| «Su usuario no tiene acceso asignado» | Alguien más ya es la dirección general; pídele que te registre en «Accesos» |
| «No se pudo conectar» | La URL del proyecto está mal escrita |
| 403 al abrir la web | La carpeta `control` está vacía: falta extraer |
