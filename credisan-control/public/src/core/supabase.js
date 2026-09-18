/* Cliente único de Supabase. Se crea una sola vez y con la clave anon:
   la seguridad real vive en las políticas de la base de datos, no aquí. */

import { config, estaConfigurado } from './config.js';

export function crearCliente() {
  if (!estaConfigurado()) return null;
  if (!window.supabase?.createClient) return null;
  return window.supabase.createClient(config.supabaseUrl, config.supabaseAnonKey, {
    auth: { persistSession: true, autoRefreshToken: true }
  });
}

/* Traduce los códigos del servidor a algo que una persona entienda.
   Si aparece uno nuevo, se muestra tal cual en vez de un "error inesperado"
   que no ayuda a nadie. */
const MENSAJES = {
  SIN_SESION:          'La sesión expiró. Vuelva a entrar.',
  SIN_PERFIL:          'Su usuario existe pero todavía no tiene acceso asignado.',
  INACTIVO:            'Su acceso está desactivado. Consulte con dirección.',
  NO_AUTORIZADO:       'Su rol no permite esta operación.',
  YA_HAY_USUARIOS:     'El sistema ya tiene usuarios registrados.',
  CODIGO_INVALIDO:     'El código de la sede debe tener de 2 a 6 letras.',
  NOMBRE_MUY_CORTO:    'El nombre es demasiado corto.',
  SEDE_YA_EXISTE:      'Ya existe una sede con ese código.',
  ROL_INVALIDO:        'El rol indicado no existe.',
  CEO_SIN_SEDE:        'La dirección general es nacional: no se le asigna una sede.',
  FALTA_SEDE:          'Hay que indicar la sede.',
  USUARIO_NO_EXISTE:   'Ese correo no existe todavía en Supabase. Créelo en Authentication → Users → Add user.',
  YA_TIENE_ACCESO:     'Esa persona ya tiene acceso al panel.',
  ORDEN_INVALIDO:      'Las horas deben ir en orden: entrada, salida a almuerzo, regreso, salida.',
  FALTAN_HORAS:        'Faltan horas por completar en ese día.',
  DIA_INEXISTENTE:     'No se encontró ese día del horario.',
  SIN_HORARIO:         'Esta sede todavía no tiene horario asignado.',
  FALTAN_DIAS:         'Hay que enviar los siete días de la semana.',
  DIA_INVALIDO:        'Hay un día fuera de rango (van de domingo a sábado).',
  DIA_REPETIDO:        'Un día de la semana viene repetido.',
  TRABAJADOR_INEXISTENTE: 'Ese trabajador no existe o fue dado de baja.',
  NO_TIENE_HORARIO_PROPIO: 'Esta persona ya se mide con el horario general de su sede.',
  YA_TIENE_OTRO_ACCESO: 'Ese correo ya tiene otro tipo de acceso al panel. Use otro correo.',
  SEDE_DESCONOCIDA:    'Una de las sedes marcadas no existe.',
  NO_ES_SOCIO:         'Esa persona no es un socio.',
  SIN_SEDES:           'Hay que marcarle al menos una sede: sin ninguna no vería nada.',
  FALTA_DOCUMENTO:     'Esta novedad no se puede aprobar sin el documento adjunto.',
  DOCUMENTO_NO_SUBIDO: 'El archivo no llegó a guardarse. Vuelva a adjuntarlo.',
  DOCUMENTO_DE_OTRA_SEDE: 'El documento quedó guardado en la sede equivocada.',
  YA_TIENE_DOCUMENTO:  'Esta novedad ya tiene documento. Sólo administración puede sustituirlo.',
  NOVEDAD_YA_RESUELTA: 'Esa novedad ya fue revisada: no admite documentos nuevos.',
  NOVEDAD_NO_ENCONTRADA: 'No se encontró esa novedad.',
  SIN_DOCUMENTO:       'Esta novedad no tiene documento adjunto.',
  EMPLEADO_INEXISTENTE: 'Ese trabajador no existe o fue dado de baja.',
  'Invalid login credentials': 'Correo o contraseña incorrectos.',
  'Email not confirmed':       'El correo aún no está confirmado en Supabase.'
};

export function traducir(error) {
  if (!error) return '';
  const bruto = error.message || String(error);
  for (const [clave, texto] of Object.entries(MENSAJES)) {
    if (bruto.includes(clave)) return texto;
  }
  if (bruto.includes('duplicate key')) {
    if (bruto.includes('national_id')) return 'Ya hay un trabajador con esa cédula.';
    if (bruto.includes('internal_code')) return 'Ya hay un trabajador con ese código interno en la sede.';
    return 'Ese registro ya existe.';
  }
  if (bruto.includes('row-level security')) {
    return 'Su rol no permite esta operación sobre esa sede.';
  }
  if (bruto.includes('Failed to fetch')) {
    return 'Sin conexión con el servidor. Revise su internet.';
  }
  return bruto;
}
