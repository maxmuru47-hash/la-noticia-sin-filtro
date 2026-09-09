# Edge Functions — contratos

Todavía no implementadas: pertenecen a las fases 2 y 3. Aquí queda fijado el
contrato para que la Fase 1 sea verificable y no haya sorpresas después.

Todas corren con `service_role`, leen `CREDISAN_PIN_PEPPER` de su entorno y no
devuelven jamás secretos.

| Función | Fase | Entrada | Hace |
|---|---|---|---|
| `admin-pin` | 2 | JWT del panel + `employee_id` | Verifica rol y sede, llama `app.generate_pin()` y `app.set_employee_pin()`. Devuelve el PIN **una sola vez** |
| `terminal-pair` | 3 | `terminal_code`, `code` | `app.redeem_pairing_code()`. Devuelve `device_token` y `signing_key` una única vez |
| `punch` | 3 | `X-Device-Token`, `terminal_code`, `pin` | `app.authenticate_terminal()` → `app.verify_pin()`. Aplica `delay_ms` antes de responder. Devuelve identidad + ticket |
| `punch-confirm` | 3 | `X-Device-Token`, `ticket`, `client_event_id`, foto | `app.register_punch()`, sube la foto al depósito privado y llama `app.attach_evidence()` o `app.mark_missing_evidence()` |
| `sync-offline` | 6 | `X-Device-Token`, lote firmado con HMAC | Verifica la firma con `signing_key`, descifra cada PIN (sealed box), resuelve el trabajador y llama `app.register_offline_punch()` por elemento |
| `evidencia-url` | 4 | JWT del panel + `event_id` | Comprueba rol y sede, emite URL firmada de 60 s y registra la consulta en `audit_logs` |
| `purgar-evidencia` | 6 | cron | `app.evidence_due_for_purge()` → borra archivos → `app.confirm_evidence_purged()` |

## Reglas

1. `service_role` **jamás** viaja al navegador.
2. La espera del PIN (`delay_ms`) la aplica la Edge Function, no la base de
   datos: retener una conexión de PostgreSQL tres segundos por cada fallo es
   regalar el servicio.
3. `punch` nunca acepta una hora enviada por el cliente. La hora es `now()` del
   servidor. La única excepción es `sync-offline`, y allí la hora declarada se
   guarda aparte (`device_timestamp`) y siempre queda marcada.
