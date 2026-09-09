# Puesta en marcha de Supabase — Fase 1

Pasos que **no** puede hacer una migración y hay que hacer a mano una sola vez.
Tiempo estimado: 20 minutos.

---

## 1. Crear el proyecto

Proyecto **independiente**, no reutilizar el de La Noticia Sin Filtro.

| Campo | Valor |
|---|---|
| Nombre | `credisan-control` |
| Región | `us-east-1` (la más cercana a Venezuela) |
| Plan | Pro recomendado (Storage, respaldos diarios y `pg_cron`) |

Guarde la contraseña de la base de datos en un gestor de claves. No va al repositorio.

## 2. Aplicar las migraciones

Con el CLI de Supabase (recomendado):

```bash
supabase link --project-ref <ref-del-proyecto>
supabase db push          # aplica supabase/migrations/ en orden
psql "$DATABASE_URL" -f supabase/seed/seed.sql
```

Sin CLI: pegue cada archivo de `supabase/migrations/` **en orden de nombre**
en el editor SQL del panel, y después `supabase/seed/seed.sql`.

Verificación rápida:

```sql
select count(*) from branches;   -- 3
select count(*) from terminals;  -- 3
select count(*) from system_settings;  -- 19
select key, value from system_settings where key like 'attendance%';
```

## 3. Activar el hook de claims  ⚠️ importante

Mete el rol y la sede dentro del token de acceso: la RLS deja de consultar
`profiles` en cada consulta.

**Authentication → Hooks → Customize Access Token (JWT) Claims**
→ activar → seleccionar `public.custom_access_token_hook`.

> El sistema funciona igual si no lo activa: las funciones de alcance caen a
> consultar `profiles`. Es correcto, sólo más lento.

## 4. Extensiones

**Database → Extensions**, habilitar si no lo están:

- `pgcrypto` (obligatoria — PIN y tokens)
- `btree_gist` (obligatoria — solapamiento de horarios)
- `pg_cron` (recomendada — recálculos y cierres)

Si `pg_cron` no está disponible, la migración `0010` lo avisa y no falla.
En ese caso, programe desde fuera (por ejemplo un cron de Hostinger que llame
a una Edge Function) las funciones `app.job_recompute_today()`,
`app.job_close_yesterday()`, `app.job_weekly_closures()` y `app.job_cleanup()`.

## 5. Storage

La migración `0009` crea los tres depósitos **privados**: `evidencia`,
`empleados`, `novedades`. Compruebe en **Storage** que ninguno figure como
público. Si alguno aparece público, la instalación está mal: deténgase.

## 6. Secretos de las Edge Functions

```bash
supabase secrets set CREDISAN_PIN_PEPPER="$(openssl rand -base64 48)"
```

Guarde ese valor en el gestor de claves de la empresa. **Si se pierde, hay que
regenerar todos los PIN**; si se filtra, también. No está en la base de datos
a propósito: así un volcado robado de PostgreSQL no permite recuperar ningún PIN.

## 7. Primer usuario CEO

Cree el usuario en **Authentication → Users → Add user** (con correo y
contraseña), copie su `id` y ejecute:

```sql
insert into profiles (id, full_name, role, branch_id)
values ('<uuid-del-usuario>', 'Nombre del CEO', 'ceo', null);
```

Es el único perfil que se crea a mano: desde la Fase 2 los demás se dan de alta
desde el panel, con auditoría.

## 8. Seguridad del proyecto

- **Settings → API**: la `service_role key` no se pega jamás en el frontend.
  Sólo en secretos de Edge Functions.
- **Authentication → Providers**: deje sólo correo + contraseña.
- **Authentication → Settings**: desactive «Enable email signups».
  Nadie se registra solo: los usuarios los crea el CEO.
- Active MFA para las cuentas de CEO y administradoras.
- **Database → Backups**: verifique que el respaldo diario esté activo.

## 9. Comprobación final

```sql
-- Ninguna tabla debe quedar sin RLS
select tablename from pg_tables t
 where schemaname = 'public'
   and not exists (select 1 from pg_class c
                    where c.relname = t.tablename and c.relrowsecurity);
-- Debe devolver 0 filas.
```

## Pruebas locales

Toda la Fase 1 se valida sin tocar Supabase:

```bash
PGPORT=54329 ./supabase/tests/run_local.sh
```

Levanta el esquema completo en un PostgreSQL local y corre la batería de
`supabase/tests/01_suite.sql`: aislamiento por sede, salario, inmutabilidad de
marcaciones, PIN, offline, Storage y auditoría.
