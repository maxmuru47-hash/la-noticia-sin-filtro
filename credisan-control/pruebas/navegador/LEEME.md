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
```

`panel-f4-test.js` termina con código 0 si todo pasa, y 1 si algo falla: sirve
para integración continua tal cual.

## Qué hay aquí

| Archivo | Qué comprueba |
|---|---|
| `mock-supabase.js` | Servidor simulado de las fases 2 y 3 |
| `mock-f4.js` | Servidor simulado de la Fase 4. Imita la respuesta **con las políticas ya aplicadas**: si el usuario es jefe de Maracaibo, Caja Seca sencillamente no existe en los datos que llegan |
| `panel-f3-test.js` | Terminales, código de vinculación y revelación del PIN |
| `panel-f4-test.js` | 52 comprobaciones sobre los tres roles: tablero del día, período, ranking, novedades y **aislamiento de datos entre sedes** |
| `kiosk-test.js` | El kiosco completo con cámara simulada, incluida la comprobación de que el PIN no vuelve a viajar al confirmar |

## Una advertencia sobre cómo escribirlas

La primera versión de la batería SQL de la Fase 4 buscaba el número `150` como
texto para asegurarse de que ningún salario se escapaba. Lo encontró dentro de
un UUID aleatorio (`…f860-4150-8e02…`) y falló sin que hubiera fuga alguna.
Pasaba o fallaba según la suerte del sorteo.

La lección vale también aquí: **no se busca lo que no debería estar, se exige
exactamente lo que sí debería**. La comprobación buena no es «que no aparezca
la palabra salario», es «que la ficha tenga estos campos y ni uno más».
