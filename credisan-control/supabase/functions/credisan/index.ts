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
// Llave privada del modo sin conexión (PKCS8 en base64). El terminal sólo
// tiene la pública: cifra el PIN y ni él mismo puede volver a abrirlo.
const LLAVE_OFFLINE = Deno.env.get('CREDISAN_OFFLINE_PRIVATE_KEY') ?? '';
const MAX_LOTE = 200;

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

        // La foto de ficha vive en un depósito PRIVADO, y el terminal no
        // tiene sesión de Supabase para leerlo. Así que se le entrega una
        // URL firmada que vive lo que dura la pantalla de identidad: el
        // tiempo justo para que el trabajador se vea y confirme.
        if (data?.ok && data?.foto) {
          try {
            const { data: firmada } = await sb.storage
              .from('empleados').createSignedUrl(String(data.foto), 90);
            if (firmada?.signedUrl) data.foto_url = firmada.signedUrl;
          } catch (_e) {
            // Sin foto se ven las iniciales. Nunca impide marcar.
          }
          // La ruta interna no le hace falta al terminal, y es un dato
          // menos viajando: se sustituye por la URL o por nada.
          delete data.foto;
        }

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
        // La sede sale de `marca`, que la trae la base de datos. NO del
        // cuerpo de la petición: ver el comentario de `guardarEvidencia`.
        await guardarEvidencia(sb, marca,
          typeof cuerpo.foto === 'string' ? cuerpo.foto : '',
          String(cuerpo.motivo_sin_foto ?? 'camara_no_disponible'));

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


      // ── Sincronizar lo que se marcó sin conexión ──────────────────
      // El terminal encola con el PIN CIFRADO y lo manda cuando vuelve la
      // señal. Aquí se abre cada sobre, se identifica a la persona y se
      // registra con la hora que declaró el dispositivo.
      //
      // Cada elemento se resuelve por separado y en su propia
      // transacción: que uno se rechace no puede tumbar a los demás. El
      // terminal borra de su cola lo aceptado y lo rechazado por un motivo
      // definitivo, y reintenta sólo lo que falló por red.
      //
      // Sobre por qué NO hay una firma HMAC por elemento, que es lo que
      // decía el diseño inicial: el `device_token` y la clave de firma
      // viven los dos en el almacenamiento del mismo navegador. Quien
      // tenga uno tiene el otro, así que firmar cada elemento no añade
      // ninguna garantía que el token no dé ya, y el tránsito lo cubre
      // TLS. Lo que sí protege de verdad es que el PIN vaya cifrado con
      // una llave que el dispositivo no posee, y eso sí está.
      case 'sincronizar': {
        const terminal = await terminalAutenticado(sb, cuerpo.terminal, cuerpo.token);
        if (!terminal) return responder({ ok: false, motivo: 'TERMINAL_NO_AUTORIZADO' }, 401);

        if (!LLAVE_OFFLINE) {
          return responder({ ok: false, motivo: 'FALTA_LLAVE_OFFLINE',
            detalle: 'Configure el secreto CREDISAN_OFFLINE_PRIVATE_KEY.' }, 500);
        }

        const lote = Array.isArray(cuerpo.lote) ? cuerpo.lote : [];
        if (!lote.length) return responder({ ok: true, resultados: [] });
        // Un tope por petición. Sin él, una tableta robada podría encolar
        // decenas de miles de PIN al azar y soltarlos de golpe.
        if (lote.length > MAX_LOTE) {
          return responder({ ok: false, motivo: 'LOTE_DEMASIADO_GRANDE', maximo: MAX_LOTE }, 400);
        }

        let privada: CryptoKey;
        try {
          privada = await importarPrivada(LLAVE_OFFLINE);
        } catch {
          return responder({ ok: false, motivo: 'LLAVE_OFFLINE_ILEGIBLE' }, 500);
        }

        const resultados = [];
        for (const item of lote) {
          const id = String(item?.id ?? '');
          let pin: string;
          try {
            pin = await abrirSobre(privada, item?.sobre);
          } catch {
            // Un sobre que no abre es basura o viene de otra llave. Se
            // responde definitivo para que el terminal lo deseche: dejarlo
            // en la cola sería reintentarlo para siempre.
            resultados.push({ id, ok: false, reason: 'SOBRE_ILEGIBLE', definitivo: true });
            continue;
          }

          if (!/^\d{6}$/.test(pin)) {
            resultados.push({ id, ok: false, reason: 'PIN_INVALIDO', definitivo: true });
            continue;
          }

          const { data, error } = await sb.rpc('edge_offline', {
            p_terminal: terminal,
            p_pin: pin,
            p_pepper: PEPPER,
            p_client_event: id,
            p_device_ts: String(item?.ts ?? ''),
            p_seq: Number(item?.seq ?? 0),
            p_drift: item?.drift === null || item?.drift === undefined
                     ? null : Math.round(Number(item.drift))
          });

          if (error) {
            // Fallo del servidor, no del elemento: que se reintente.
            resultados.push({ id, ok: false, reason: limpiar(error.message), definitivo: false });
            continue;
          }
          // La foto se encoló junto a la marcación y sube por el mismo
          // camino que la del flujo en línea. Si no subiera, la marcación
          // queda igualmente registrada y sin evidencia, nunca perdida.
          if (data?.ok) {
            await guardarEvidencia(sb, data,
              typeof item?.foto === 'string' ? item.foto : '',
              'sin_camara_offline');
          }
          resultados.push({ ...data, id, definitivo: true });
        }

        return responder({ ok: true, resultados });
      }


      // ── Ver la fotografía de una marcación ────────────────────────
      // El depósito de evidencia NO tiene políticas para usuarios: ni el
      // CEO lo lee directamente. Se pasa por aquí a propósito, para que
      // toda consulta quede registrada.
      //
      // El permiso lo decide la base de datos con el token de la persona
      // —`evidencia_de` comprueba rol y sede, y escribe el rastro—. Esta
      // función sólo firma la URL, que es lo único que la base no puede
      // hacer por sí misma.
      case 'evidencia': {
        const cabecera = peticion.headers.get('Authorization') ?? '';
        const jwt = cabecera.replace(/^Bearer\s+/i, '');
        if (!jwt) return responder({ ok: false, motivo: 'SIN_SESION' }, 401);

        const usuario = comoUsuario(jwt);
        const { data: quien, error: eSesion } = await usuario.auth.getUser();
        if (eSesion || !quien?.user) return responder({ ok: false, motivo: 'SIN_SESION' }, 401);

        const evento = String(cuerpo.evento ?? '');
        const { data: permiso, error: ePermiso } =
          await usuario.rpc('evidencia_de', { p_event: evento });
        if (ePermiso) return responder({ ok: false, motivo: limpiar(ePermiso.message) }, 403);
        if (!permiso?.ok) return responder(permiso ?? { ok: false, motivo: 'NO_AUTORIZADO' });

        // ANTES DE FIRMAR, COMPROBAR QUE EL ARCHIVO ESTÁ.
        //
        // `createSignedUrl` no comprueba nada: firma un texto. Si en esa
        // ruta no hay archivo, la firma sale perfecta y el navegador se
        // come un 404 — y el panel sólo podía decir «no se pudo cargar
        // la fotografía», que tapa cuatro causas distintas y no explica
        // ninguna. Administración se queda sin saber si el problema es
        // el permiso, la red, o que la foto no existe.
        //
        // Si la base dice que hay fotografía y el depósito dice que no,
        // eso no es un fallo de pantalla: es una contradicción entre dos
        // sistemas, y hay que nombrarla.
        const ruta = String(permiso.ruta);
        const corte = ruta.lastIndexOf('/');
        const carpeta = corte > 0 ? ruta.slice(0, corte) : '';
        const archivo = ruta.slice(corte + 1);

        const { data: hallado } = await sb.storage
          .from('evidencia').list(carpeta, { search: archivo, limit: 100 });

        if (!hallado?.some((o) => o.name === archivo)) {
          // La ruta NO viaja al navegador —eso se decidió en la fase 9—
          // pero sí al registro del servidor, que es donde se mira
          // cuando hay que averiguar qué pasó.
          console.error('evidencia ausente en el depósito', { evento, ruta });
          return responder({ ok: false, motivo: 'EVIDENCIA_NO_ESTA' });
        }

        // Sesenta segundos: el tiempo de mirarla, no de repartirla.
        const { data: firmada, error: eFirma } = await sb.storage
          .from('evidencia').createSignedUrl(ruta, 60);
        if (eFirma || !firmada?.signedUrl) {
          console.error('no se pudo firmar la evidencia', { evento, error: eFirma?.message });
          return responder({ ok: false, motivo: 'NO_SE_PUDO_FIRMAR' }, 500);
        }

        return responder({
          ok: true, url: firmada.signedUrl,
          nombre: permiso.nombre, cuando: permiso.cuando,
          evento: permiso.evento, fecha: permiso.fecha
        });
      }


      // ── Purga de evidencia vencida ────────────────────────────────
      // La retención es un compromiso con los trabajadores: su fotografía
      // no se guarda para siempre. PostgreSQL sabe cuáles caducaron pero
      // no puede borrar archivos, así que lo hace esta función.
      //
      // Se borra EL ARCHIVO. La marcación, su hora, su clasificación y su
      // auditoría permanecen: lo que caduca es la imagen de la cara de
      // una persona, no el registro de que trabajó ese día.
      //
      // La llama el mantenimiento nocturno con la llave de servicio. No
      // hay forma de dispararla desde el panel ni desde el terminal.
      case 'purgar': {
        // La credencial va en la CABECERA, no en el cuerpo: un cuerpo de
        // petición acaba con facilidad en un registro de errores, y ésta
        // es la llave que lo abre todo. Y se compara en tiempo constante,
        // porque una comparación normal se rinde en el primer carácter
        // distinto y eso, medido muchas veces, filtra la llave.
        const dada = (peticion.headers.get('Authorization') ?? '').replace(/^Bearer\s+/i, '');
        if (!LLAVE_MAESTRA || !igualSinFiltrar(dada, LLAVE_MAESTRA)) {
          return responder({ ok: false, motivo: 'NO_AUTORIZADO' }, 401);
        }

        const { data: pendientes, error } = await sb.rpc('edge_evidencia_por_purgar', {
          p_limite: Math.min(Number(cuerpo.limite ?? 200), 500)
        });
        if (error) return responder({ ok: false, motivo: limpiar(error.message) }, 400);
        if (!pendientes?.length) return responder({ ok: true, purgadas: 0, quedan: 0 });

        // Se confirma SÓLO lo que de verdad se borró, y eso hay que mirarlo
        // en la respuesta, no darlo por hecho: `remove` NO devuelve error
        // por una ruta que no existe; simplemente la deja fuera de la
        // lista de borrados. Confiando en el error se marcarían como
        // purgadas fotografías que siguen en el depósito, y el sistema
        // diría que cumplió una promesa que no cumplió.
        const rutas = pendientes.map((p: any) => String(p.ruta));
        const { data: borradas, error: eBorrado } =
          await sb.storage.from('evidencia').remove(rutas);
        if (eBorrado) return responder({ ok: false, motivo: 'NO_SE_PUDO_BORRAR',
                                         detalle: eBorrado.message.slice(0, 120) }, 500);

        const hechas = new Set((borradas ?? []).map((o: any) => String(o.name)));
        const ids = pendientes
          .filter((p: any) => hechas.has(String(p.ruta)))
          .map((p: any) => String(p.id));

        if (ids.length) {
          const { error: eConfirmar } = await sb.rpc('edge_confirmar_purga', { p_ids: ids });
          if (eConfirmar) return responder({ ok: false, motivo: limpiar(eConfirmar.message) }, 500);
        }

        // Lo que no se pudo borrar se queda pendiente y se reintenta la
        // próxima noche. Se informa, porque si el número no baja nunca hay
        // algo que mirar.
        const fallidas = pendientes.length - ids.length;
        return responder({ ok: true, purgadas: ids.length, sin_borrar: fallidas,
                           quedan: pendientes.length === 500 ? 'mas' : 0 });
      }


      // ── ¿Está viva y configurada? ─────────────────────────────────
      // La usan el panel y la página de estado para poder decir POR QUÉ
      // no se pueden generar PIN, en vez de dejar a alguien mirando una
      // lista de «PIN pendiente» sin explicación.
      //
      // Responde 200 a propósito: preguntar por una acción inexistente
      // también servía, pero dejaba un error 400 en la consola del
      // navegador cada vez que alguien entraba al panel.
      //
      // No dice nada que no se pueda decir sin sesión: si está publicada
      // y si tiene sus secretos. Ni versiones, ni rutas, ni nombres.
      case 'estado': {
        return responder({
          ok: true,
          pimienta: PEPPER.length > 0,
          sin_conexion: LLAVE_OFFLINE.length > 0
        });
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

// El buffer se reserva explícitamente para que el tipo resultante sea
// `Uint8Array<ArrayBuffer>` y no `Uint8Array<ArrayBufferLike>`. Con el
// segundo, WebCrypto no lo acepta como `BufferSource` al comprobar tipos:
// en ejecución funciona igual, pero el código debe ser correcto también
// para quien lo compile, no sólo para quien lo ejecute.
// Comparación que tarda lo mismo acierte o falle. Con `===`, el tiempo de
// respuesta depende de cuántos caracteres coincidieron, y quien pruebe
// muchas veces puede reconstruir la llave carácter a carácter.
function igualSinFiltrar(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let dif = 0;
  for (let i = 0; i < a.length; i++) dif |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return dif === 0;
}

function aBinario(base64: string): Uint8Array<ArrayBuffer> {
  const crudo = atob(base64);
  const bytes = new Uint8Array(new ArrayBuffer(crudo.length));
  for (let i = 0; i < crudo.length; i++) bytes[i] = crudo.charCodeAt(i);
  return bytes;
}

// ── Evidencia fotográfica, compartida por los dos caminos ────────────
// La usan `confirmar` (en línea) y `sincronizar` (lo que se marcó sin
// conexión). La regla es la misma en ambos y por eso vive en un solo
// sitio: la marcación ya está registrada cuando se llega aquí, así que
// nada de lo que pase con la foto puede tumbarla.
const ES_UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

async function guardarEvidencia(
  sb: ReturnType<typeof admin>, marca: any,
  foto: string, motivoSinFoto: string
): Promise<void> {
  if (!marca?.id || marca.duplicado) return;
  const evento = marca.id as string;

  // La sede es el PRIMER SEGMENTO de la ruta, y ese segmento es lo que
  // decide después quién puede leer el archivo. Antes venía en el cuerpo
  // de la petición, es decir: la elegía el terminal, que es un teléfono
  // en un mostrador y hay que tratarlo como hostil. Cambiando un valor
  // en su propio almacenamiento podía hacer que esta función —con la
  // llave maestra— escribiera bajo la sede que quisiera, o con `..`
  // fuera del depósito entero.
  //
  // Ahora sale de `marca.branch_id`, que lo pone la base de datos al
  // escribir la marcación. Y aun así se comprueba que sea un UUID: una
  // ruta se construye pegando texto, y ahí no se confía en nadie.
  const branchId = String(marca.branch_id ?? '');
  if (!ES_UUID.test(branchId)) {
    await sb.rpc('edge_sin_evidencia', { p_event: evento, p_motivo: 'sede_no_resuelta' });
    marca.evidencia = 'sin_evidencia';
    return;
  }

  if (!foto.startsWith('data:image/jpeg;base64,')) {
    await sb.rpc('edge_sin_evidencia', {
      p_event: evento, p_motivo: motivoSinFoto.slice(0, 60)
    });
    marca.evidencia = 'sin_evidencia';
    return;
  }

  try {
    const bytes = aBinario(foto.split(',')[1]);
    const f = new Date(marca.recorded_at);
    const ruta = [
      branchId, f.getUTCFullYear(),
      String(f.getUTCMonth() + 1).padStart(2, '0'),
      String(f.getUTCDate()).padStart(2, '0'),
      `${evento}.jpg`
    ].join('/');

    const { error } = await sb.storage
      .from('evidencia')
      .upload(ruta, bytes, { contentType: 'image/jpeg', upsert: true });
    if (error) throw new Error(error.message);

    await sb.rpc('edge_evidencia', {
      p_event: evento, p_path: ruta, p_bytes: bytes.length, p_captured: marca.recorded_at
    });
    marca.evidencia = 'almacenada';
  } catch (_e) {
    await sb.rpc('edge_sin_evidencia', { p_event: evento, p_motivo: 'fallo_al_guardar' });
    marca.evidencia = 'sin_evidencia';
  }
}

// ── Sobre cerrado para el PIN sin conexión ───────────────────────────
// El terminal genera un par efímero, deriva un secreto compartido con la
// llave pública del servidor (ECDH P-256), lo pasa por HKDF y cifra el PIN
// con AES-GCM. Aquí se rehace el mismo camino con la llave privada.
//
// Consecuencia práctica: el PIN encolado en la tableta no lo puede leer ni
// la tableta. Si el aparato se pierde con marcaciones sin enviar, lo que
// se pierde son las marcaciones, nunca los PIN.

async function importarPrivada(pkcs8Base64: string): Promise<CryptoKey> {
  return await crypto.subtle.importKey(
    'pkcs8', aBinario(pkcs8Base64),
    { name: 'ECDH', namedCurve: 'P-256' }, false, ['deriveBits']);
}

async function abrirSobre(
  privada: CryptoKey,
  sobre: { epk?: string; iv?: string; ct?: string } | undefined
): Promise<string> {
  if (!sobre?.epk || !sobre?.iv || !sobre?.ct) throw new Error('SOBRE_INCOMPLETO');

  const publicaEfimera = await crypto.subtle.importKey(
    'raw', aBinario(sobre.epk),
    { name: 'ECDH', namedCurve: 'P-256' }, false, []);

  const compartido = await crypto.subtle.deriveBits(
    { name: 'ECDH', public: publicaEfimera }, privada, 256);

  const material = await crypto.subtle.importKey('raw', compartido, 'HKDF', false, ['deriveKey']);
  const llave = await crypto.subtle.deriveKey(
    { name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array(new ArrayBuffer(0)),
      info: new TextEncoder().encode('credisan-pin-offline-v1') },
    material, { name: 'AES-GCM', length: 256 }, false, ['decrypt']);

  const claro = await crypto.subtle.decrypt(
    { name: 'AES-GCM', iv: aBinario(sobre.iv) }, llave, aBinario(sobre.ct));

  return new TextDecoder().decode(claro);
}
