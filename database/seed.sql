-- =====================================================================
-- LA NOTICIA SIN FILTRO · Datos base
--
-- Este archivo carga SOLO lo estructural: roles, capacidades, categorías
-- editoriales y ajustes. No crea usuarios con contraseña ni contenido:
-- eso lo hacen los scripts, para no dejar credenciales en el repositorio.
--
--   php scripts/crear-admin.php     crea la primera cuenta
--   php scripts/sembrar-demo.php    crea el contenido de demostración
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- ROLES Y CAPACIDADES
-- Los permisos se comprueban por capacidad, no por nombre de rol.
-- El rol superior es el ÚNICO con eliminación definitiva.
-- ---------------------------------------------------------------------

INSERT INTO roles (slug, name, description, `rank`, capabilities) VALUES
('autor', 'Autor',
 'Crea y edita sus propios borradores. No publica.',
 10,
 '["articulo.crear","articulo.editar_propio","medios.gestionar"]'),

('moderador', 'Moderación',
 'Filtra preguntas, experiencias y posible abuso de la comunidad.',
 20,
 '["comunidad.moderar"]'),

('productor', 'Productor multimedia',
 'Convierte piezas en video, clips y Lives. Sube pósteres, subtítulos y transcripciones.',
 25,
 '["medios.gestionar","video.gestionar","live.gestionar"]'),

('editor', 'Editor responsable',
 'Verifica, clasifica, corrige, publica, archiva y protege los estándares.',
 50,
 '["articulo.crear","articulo.editar_propio","articulo.editar_cualquiera","articulo.publicar","articulo.archivar","articulo.retirar","medios.gestionar","video.gestionar","live.gestionar","taxonomia.gestionar","comunidad.moderar","portada.gestionar","metricas.ver"]'),

('administrador', 'Administrador',
 'Todo lo editorial, más usuarios, redirecciones y auditoría. No elimina piezas de forma definitiva.',
 80,
 '["articulo.crear","articulo.editar_propio","articulo.editar_cualquiera","articulo.publicar","articulo.archivar","articulo.retirar","medios.gestionar","video.gestionar","live.gestionar","taxonomia.gestionar","comunidad.moderar","portada.gestionar","redirecciones.gestionar","usuarios.gestionar","auditoria.ver","metricas.ver"]'),

('superadministrador', 'Superadministrador',
 'Rol superior. Único que puede eliminar definitivamente una pieza, con confirmación reforzada y registro de auditoría.',
 100,
 '["*"]')
ON DUPLICATE KEY UPDATE
  name = VALUES(name), description = VALUES(description),
  `rank` = VALUES(`rank`), capabilities = VALUES(capabilities);

-- ---------------------------------------------------------------------
-- CATEGORÍAS
-- Cada línea editorial responde una pregunta concreta.
-- ---------------------------------------------------------------------

INSERT INTO categories (slug, name, question, description, color, position) VALUES
('venezuela', 'Venezuela',
 '¿Qué cambia realmente?',
 'Lo que ocurre en el país, con lo confirmado separado de lo probable.',
 '#C1121F', 1),

('dinero-real', 'Dinero real',
 '¿Cómo afecta bolsillo y negocio?',
 'Economía explicada en consecuencias concretas, no en jerga.',
 '#1F6F43', 2),

('emprendimiento', 'Emprendimiento',
 '¿Qué puede hacer una empresa?',
 'Casos, guías y datos para quien construye algo.',
 '#1B4D7A', 3),

('sociedad-y-familia', 'Sociedad y familia',
 '¿Qué estamos normalizando?',
 'Debates que afectan la vida diaria, sin sensacionalismo.',
 '#A66A00', 4),

('ia-y-tecnologia', 'IA y tecnología',
 '¿Cómo se aplica sin humo?',
 'Tecnología demostrada, no prometida.',
 '#6B3FA0', 5)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), question = VALUES(question),
  description = VALUES(description), color = VALUES(color), position = VALUES(position);

-- ---------------------------------------------------------------------
-- AJUSTES
-- ---------------------------------------------------------------------

INSERT INTO settings (name, value) VALUES
('esquema_version', '1'),
('sembrado_en', ''),
('nota_metodologia_pulso',
 'Voto anónimo y no representativo. No es una encuesta científica: es una señal de cómo se mueve la conversación entre quienes leen esta pieza. Los resultados no se editan nunca.')
ON DUPLICATE KEY UPDATE value = VALUES(value);
