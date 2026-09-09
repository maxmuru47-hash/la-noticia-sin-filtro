# `src/` — módulos del cliente

Vacío a propósito: la Fase 1 es sólo backend. Distribución acordada, para que
las fases siguientes no improvisen:

```
core/    supabase.js · config.js · session.js · time.js · errors.js
kiosk/   boot.js · device.js · pinpad.js · camera.js · punch.service.js · queue.js · screens/
panel/   router.js · guards.js · views/{ceo,admin,jefe,shared} · components/
domain/  schedule.js · classification.js · formatters.js
ui/      tokens.css · base.css · kiosk.css · panel.css · a11y.css
```

Reglas que no se negocian en ninguna fase:

- `guards.js` es experiencia de usuario, **no** seguridad. La seguridad está en la RLS.
- `domain/classification.js` sólo pinta etiquetas y colores: **quien clasifica es PostgreSQL**.
- Ningún módulo escribe en `attendance_events`: se pasa por la Edge Function.
- El dominio y las claves salen de `config/env.js`, nunca escritos en el código.
