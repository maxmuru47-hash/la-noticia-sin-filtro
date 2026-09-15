// =====================================================================
// CREDISAN CONTROL · Función de servidor
// =====================================================================
// Única puerta entre el terminal de marcación y la base de datos.
//
// El terminal es un teléfono en un mostrador: hay que tratarlo como un
// dispositivo hostil. Por eso NO es un usuario de Supabase y no tiene
// acceso a ninguna tabla. Sólo puede pedirle a esta función que registre
// una marcación, y esta función decide.
//
// Aquí vive el `pepper` del PIN, que nunca se guarda en la base de datos:
// un volcado robado de PostgreSQL no permite recuperar ni un solo PIN.
// =====================================================================

import { createClient } from 'https://esm.sh/@supabase/supabase-js@2.45.4';

const URL_SUPABASE = Deno.env.get('SUPABASE_URL')!;
const LLAVE_MAESTRA = Deno.env.get('SUPABASE_SERVICE_ROLE_KEY')!;
// Los proyectos nuevos usan claves `sb_publishable_…` en vez de la anon
// clásica. Se acepta cualquiera de las dos, y si no hubiera ninguna se usa
// la llave maestra sólo como credencial de transporte: el rol efectivo lo
// sigue fijando el token de la persona en la cabecera Authorization.
const LLAVE_PUBLICA = Deno.env.get('SUPABASE_ANON_KEY')
  ?? Deno.env.get('SUPABASE_PUBLISHABLE_KEY')
  ?? LLAVE_MAESTRA;
const PEPPER = Deno.env.get('CREDISAN_PIN_PEPPER') ?? '';

const CORS = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Headers': 'authorization, x-client-info, apikey, content-type',
  'Access-Control-Allow-Methods': 'POST, OPTIONS'
};

const responder = (cuerpo: unknown, estado = 200) =>
  new Response(JSON.stringify(cuerpo), {
    status: estado,
    headers: { ...CORS, 'Content-Type': 'application/json' }
  });

const esperar = (ms: number) => new Promise((r) => setTimeout(r, ms));

const admin = () => createClient(URL_SUPABASE, LLAVE_MAESTRA, { auth: { persistSession: false } });

// Cliente que actúa CON EL TOKEN DE LA PERSONA: así los permisos los sigue
// decidiendo la RLS, no esta función.
const comoUsuario = (jwt: string) =>
  createClient(URL_SUPABASE, LLAVE_PUBLICA, {
    global: { headers: { Authorization: `Bearer ${jwt}` } },
    auth: { persistSession: false }
  });

// ── Comprobación de identidad del terminal ───────────────────────────
// Todas las acciones del kiosco pasan por aquí primero.
async function terminalAutenticado(sb: ReturnType<typeof admin>, codigo: string, token: string) {
  if (!codigo || !token) return null;
  const { data, error } = await sb.rpc('edge_autenticar_terminal', {
    p_code: codigo, p_token: token
  });
  if (error || !data) return null;
  return data as string;
}

Deno.serve(async (peticion) => {
  if (peticion.method === 'OPTIONS') return new Response('ok', { headers: CORS });
  if (peticion.method !== 'POST') return responder({ ok: false, motivo: 'METODO_NO_PERMITIDO' }, 405);

  if (!PEPPER) {
    return responder({ ok: false, motivo: 'FALTA_PEPPER',
      detalle: 'Configure el secreto CREDISAN_PIN_PEPPER en Edge Functions.' }, 500);
  }

  let cuerpo: Record<string, any>;
  try {
    cuerpo = await peticion.json();
  } catch {
    return responder({ ok: false, motivo: 'CUERPO_INVALIDO' }, 400);
  }

  const sb = admin();
  const accion = String(cuerpo.accion ?? '');

  try {
    switch (accion) {

      // ── Emparejar el dispositivo con su sede ──────────────────────
      // Se hace una sola vez por teléfono. El código de 8 caracteres lo
      // genera administración desde el panel y vive 10 minutos.
      case 'emparejar': {
        const { data, error } = await sb.rpc('edge_emparejar', {
          p_terminal_code: String(cuerpo.terminal ?? '').toUpperCase().trim(),
          p_code: String(cuerpo.codigo ?? '').toUpperCase().trim(),
          p_device: String(cuerpo.dispositivo ?? 'Sin identificar').slice(0, 120)
        });
        if (error) return responder({ ok: false, motivo: limpiar(error.message) }, 400);
        return responder(data);
      }

      // ── Paso 1 de la marcación: el PIN ────────────────────────────
      // El PIN viaja una sola vez y se cambia por un ticket de 60 s.
      case 'pin': {
        const terminal = await terminalAutenticado(sb, cuerpo.terminal, cuerpo.token);
        if (!terminal) return responder({ ok: false, motivo: 'TERMINAL_NO_AUTORIZADO' }, 401);

        const pin = String(cuerpo.pin ?? '');
        if (!/^\d{6}$/.test(pin)) {
          await esperar(300);
          return responder({ ok: false, motivo: 'PIN_INVALIDO' });
        }

        const { data, error } = await sb.rpc('edge_verificar_pin', {
          p_terminal: terminal, p_pin: pin, p_pepper: PEPPER
        });
        if (error) return responder({ ok: false, motivo: limpiar(error.message) }, 400);

        // La espera progresiva la aplica esta función, no la base de datos:
        // retener una conexión de PostgreSQL por cada fallo sería regalar
        // el servicio a quien pruebe PIN al azar.
        const espera = Number(data?.espera_ms ?? 0);
        if (!data?.ok && espera > 0) await esperar(Math.min(espera, 5000));

        delete data.espera_ms;
        return responder(data);
      }

      // ── Paso 2: confirmar, registrar y guardar la fotografía ──────
      case 'confirmar': {
        const terminal = await terminalAutenticado(sb, cuerpo.terminal, cuerpo.token);
        if (!terminal) return responder({ ok: false, motivo: 'TERMINAL_NO_AUTORIZADO' }, 401);

        const { data: marca, error } = await sb.rpc('edge_registrar', {
          p_ticket: String(cuerpo.ticket ?? ''),
          p_client: String(cuerpo.evento_id ?? '')
        });
        if (error) return responder({ ok: false, motivo: limpiar(error.message) }, 400);

        // La marcación ya está registrada. A partir de aquí, un fallo con la
        // fotografía NO puede tumbarla: se anota que no hay evidencia y se
        // abre una novedad, pero la hora del trabajador queda guardada.
        const evento = marca.id as string;
        if (!marca.duplicado) {
          const foto = typeof cuerpo.foto === 'string' ? cuerpo.foto : '';
          if (foto.startsWith('data:image/jpeg;base64,')) {
            try {
              const bytes = aBinario(foto.split(',')[1]);
              const f = new Date(marca.recorded_at);
              const ruta = [
                cuerpo.branch_id, f.getUTCFullYear(),
                String(f.getUTCMonth() + 1).padStart(2, '0'),
                String(f.getUTCDate()).padStart(2, '0'),
                `${evento}.jpg`
              ].join('/');

              const { error: eSubida } = await sb.storage
                .from('evidencia')
                .upload(ruta, bytes, { contentType: 'image/jpeg', upsert: true });

              if (eSubida) throw new Error(eSubida.message);

              await sb.rpc('edge_evidencia', {
                p_event: evento, p_path: ruta, p_bytes: bytes.length, p_captured: marca.recorded_at
              });
              marca.evidencia = 'almacenada';
            } catch (_e) {
              await sb.rpc('edge_sin_evidencia', { p_event: evento, p_motivo: 'fallo_al_guardar' });
              marca.evidencia = 'sin_evidencia';
            }
          } else {
            await sb.rpc('edge_sin_evidencia', {
              p_event: evento,
              p_motivo: String(cuerpo.motivo_sin_foto ?? 'camara_no_disponible').slice(0, 60)
            });
            marca.evidencia = 'sin_evidencia';
          }
        }

        return responder({ ok: true, ...marca });
      }

      // ── Generar el PIN de un trabajador (desde el panel) ──────────
      case 'asignar-pin': {
        const cabecera = peticion.headers.get('Authorization') ?? '';
        const jwt = cabecera.replace(/^Bearer\s+/i, '');
        if (!jwt) return responder({ ok: false, motivo: 'SIN_SESION' }, 401);

        const usuario = comoUsuario(jwt);
        const { data: quien, error: eSesion } = await usuario.auth.getUser();
        if (eSesion || !quien?.user) return responder({ ok: false, motivo: 'SIN_SESION' }, 401);

        const empleado = String(cuerpo.empleado ?? '');

        // El permiso lo decide la RLS con el token de la persona, no esta función.
        const { data: puede, error: ePermiso } =
          await usuario.rpc('puedo_gestionar_empleado', { p_employee: empleado });
        if (ePermiso || !puede) return responder({ ok: false, motivo: 'NO_AUTORIZADO' }, 403);

        const { data, error } = await sb.rpc('edge_asignar_pin', {
          p_employee: empleado, p_pepper: PEPPER, p_actor: quien.user.id
        });
        if (error) return responder({ ok: false, motivo: limpiar(error.message) }, 400);

        // El PIN en claro se devuelve UNA vez. Después no existe en ninguna parte.
        return responder(data);
      }

      default:
        return responder({ ok: false, motivo: 'ACCION_DESCONOCIDA' }, 400);
    }
  } catch (e) {
    return responder({ ok: false, motivo: 'ERROR_INTERNO', detalle: String(e).slice(0, 200) }, 500);
  }
});

// Los códigos del motor vienen como 'TICKET_VENCIDO' dentro de un mensaje
// largo de PostgreSQL. Al kiosco le interesa el código, no la traza.
function limpiar(mensaje: string): string {
  const m = mensaje.match(/[A-Z_]{5,}/);
  return m ? m[0] : mensaje.slice(0, 120);
}

function aBinario(base64: string): Uint8Array {
  const crudo = atob(base64);
  const bytes = new Uint8Array(crudo.length);
  for (let i = 0; i < crudo.length; i++) bytes[i] = crudo.charCodeAt(i);
  return bytes;
}
