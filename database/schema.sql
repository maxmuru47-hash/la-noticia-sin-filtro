-- =====================================================================
-- LA NOTICIA SIN FILTRO · Esquema base
-- MySQL 8.0+ / MariaDB 10.4+  ·  InnoDB  ·  utf8mb4
--
-- REGLA FUNDACIONAL: ninguna noticia publicada se elimina jamas.
-- Los estados cambian, las URL no. Ver database/migrations/ para el
-- historial incremental.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. IDENTIDAD Y PERMISOS
-- ---------------------------------------------------------------------

CREATE TABLE roles (
  id            TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(40)  NOT NULL,
  name          VARCHAR(80)  NOT NULL,
  description   VARCHAR(255) NULL,
  `rank`        TINYINT UNSIGNED NOT NULL DEFAULT 10,
  capabilities  JSON NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid               CHAR(36) NOT NULL,
  role_id            TINYINT UNSIGNED NOT NULL,
  email              VARCHAR(190) NOT NULL,
  password_hash      VARCHAR(255) NOT NULL,
  display_name       VARCHAR(120) NOT NULL,
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at      DATETIME NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at         DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_uuid (uuid),
  KEY idx_users_role (role_id),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  identifier  VARCHAR(190) NOT NULL,
  ip_hash     CHAR(64) NOT NULL,
  successful  TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attempts_lookup (identifier, attempted_at),
  KEY idx_attempts_ip (ip_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Autor editorial. Se separa de `users` a proposito: una firma ppublica
-- puede sobrevivir a la cuenta de acceso que la creo.
CREATE TABLE authors (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid         CHAR(36) NOT NULL,
  user_id      INT UNSIGNED NULL,
  slug         VARCHAR(120) NOT NULL,
  name         VARCHAR(120) NOT NULL,
  role_title   VARCHAR(120) NULL,
  bio          TEXT NULL,
  photo_path   VARCHAR(255) NULL,
  photo_alt    VARCHAR(255) NULL,
  email        VARCHAR(190) NULL,
  instagram    VARCHAR(80) NULL,
  tiktok       VARCHAR(80) NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_authors_slug (slug),
  UNIQUE KEY uq_authors_uuid (uuid),
  KEY idx_authors_user (user_id),
  CONSTRAINT fk_authors_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. TAXONOMIAS
-- ---------------------------------------------------------------------

CREATE TABLE categories (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(120) NOT NULL,
  name        VARCHAR(120) NOT NULL,
  question    VARCHAR(190) NULL COMMENT 'Pregunta que responde esta linea editorial',
  description VARCHAR(500) NULL,
  color       VARCHAR(7) NULL,
  position    SMALLINT NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tags (
  id         MEDIUMINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug       VARCHAR(120) NOT NULL,
  name       VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tags_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tema = eje de seguimiento a largo plazo. El documento maestro lo exige
-- para el archivo y el buscador; el prompt no lo listaba como tabla.
CREATE TABLE topics (
  id          MEDIUMINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(120) NOT NULL,
  name        VARCHAR(160) NOT NULL,
  summary     VARCHAR(500) NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_topics_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. NUCLEO EDITORIAL
-- ---------------------------------------------------------------------

CREATE TABLE articles (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Identificador interno inmutable',
  uuid              CHAR(36) NOT NULL,
  slug              VARCHAR(190) NOT NULL COMMENT 'URL canonica permanente. Nunca cambia con el titulo.',
  title             VARCHAR(255) NOT NULL,
  subtitle          VARCHAR(500) NULL,
  lead_question     VARCHAR(255) NULL COMMENT 'Pregunta principal de la Noticia Viva',
  summary           TEXT NULL COMMENT 'Resumen / bajada',
  summary_60s       TEXT NULL COMMENT 'Modo de profundidad: 60 segundos',
  summary_5min      TEXT NULL COMMENT 'Modo de profundidad: 5 minutos',
  body_blocks       LONGTEXT NULL COMMENT 'JSON de bloques. Modo Sin Filtro.',
  body_plain        LONGTEXT NULL COMMENT 'Derivado en texto plano para indexar',
  editorial_type    ENUM('noticia','analisis','opinion','explicador','verificacion') NOT NULL DEFAULT 'noticia',
  status            ENUM('borrador','revision','programada','publicada','actualizada','archivada','retirada') NOT NULL DEFAULT 'borrador',
  category_id       SMALLINT UNSIGNED NULL,
  author_id         INT UNSIGNED NULL,
  editor_id         INT UNSIGNED NULL COMMENT 'Editor responsable',
  dossier_id        INT UNSIGNED NULL,
  facts_confirmed   TEXT NULL COMMENT 'Confirmado',
  facts_probable    TEXT NULL COMMENT 'Probable',
  facts_unknown     TEXT NULL COMMENT 'Todavia desconocido',
  perspective_a_title VARCHAR(190) NULL,
  perspective_a_body  TEXT NULL,
  perspective_b_title VARCHAR(190) NULL,
  perspective_b_body  TEXT NULL,
  max_opinion       TEXT NULL COMMENT 'Opinion firmada, siempre rotulada',
  legacy_question   VARCHAR(500) NULL COMMENT 'Pregunta viva que queda abierta',
  hero_media_id     INT UNSIGNED NULL,
  hero_alt          VARCHAR(255) NULL,
  reading_minutes   SMALLINT UNSIGNED NULL,
  is_demo           TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Contenido de demostracion, rotulado en pantalla',
  seo_title         VARCHAR(255) NULL,
  seo_description   VARCHAR(500) NULL,
  social_title      VARCHAR(255) NULL,
  social_description VARCHAR(500) NULL,
  social_image_path VARCHAR(255) NULL,
  noindex           TINYINT(1) NOT NULL DEFAULT 0,
  withdrawn_reason  TEXT NULL COMMENT 'Motivo publico cuando status = retirada',
  published_at      DATETIME NULL,
  scheduled_for     DATETIME NULL,
  updated_content_at DATETIME NULL COMMENT 'Ultima actualizacion editorial relevante',
  archived_at       DATETIME NULL,
  view_count        INT UNSIGNED NOT NULL DEFAULT 0,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at        DATETIME NULL COMMENT 'Solo rol superior. Nunca se usa en operacion normal.',
  PRIMARY KEY (id),
  UNIQUE KEY uq_articles_slug (slug),
  UNIQUE KEY uq_articles_uuid (uuid),
  KEY idx_articles_status_pub (status, published_at),
  KEY idx_articles_category (category_id, published_at),
  KEY idx_articles_author (author_id, published_at),
  KEY idx_articles_type (editorial_type, published_at),
  KEY idx_articles_dossier (dossier_id),
  KEY idx_articles_published (published_at),
  KEY idx_articles_scheduled (scheduled_for),
  CONSTRAINT fk_articles_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL,
  CONSTRAINT fk_articles_author   FOREIGN KEY (author_id)   REFERENCES authors (id)    ON DELETE SET NULL,
  CONSTRAINT fk_articles_editor   FOREIGN KEY (editor_id)   REFERENCES authors (id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Slugs quemados: una URL usada jamas se reutiliza para otra noticia.
CREATE TABLE reserved_slugs (
  slug        VARCHAR(190) NOT NULL,
  article_id  INT UNSIGNED NULL,
  reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE article_revisions (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  article_id   INT UNSIGNED NOT NULL,
  revision_no  INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NULL,
  title        VARCHAR(255) NULL,
  subtitle     VARCHAR(500) NULL,
  summary      TEXT NULL,
  body_blocks  LONGTEXT NULL,
  status       VARCHAR(20) NULL,
  change_note  VARCHAR(500) NULL,
  snapshot     LONGTEXT NULL COMMENT 'JSON completo del articulo en ese momento',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_revision (article_id, revision_no),
  KEY idx_revisions_article (article_id, created_at),
  CONSTRAINT fk_revisions_article FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE CASCADE,
  CONSTRAINT fk_revisions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE article_corrections (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  article_id   INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NULL,
  kind         ENUM('correccion','actualizacion','aclaracion','retiro') NOT NULL DEFAULT 'actualizacion',
  reason       VARCHAR(500) NOT NULL COMMENT 'Motivo, visible al publico',
  detail       TEXT NULL COMMENT 'Que cambio exactamente',
  is_public    TINYINT(1) NOT NULL DEFAULT 1,
  corrected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_corrections_article (article_id, corrected_at),
  CONSTRAINT fk_corrections_article FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE CASCADE,
  CONSTRAINT fk_corrections_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE article_tags (
  article_id INT UNSIGNED NOT NULL,
  tag_id     MEDIUMINT UNSIGNED NOT NULL,
  PRIMARY KEY (article_id, tag_id),
  KEY idx_article_tags_tag (tag_id),
  CONSTRAINT fk_at_article FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE CASCADE,
  CONSTRAINT fk_at_tag     FOREIGN KEY (tag_id)     REFERENCES tags (id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE article_topics (
  article_id INT UNSIGNED NOT NULL,
  topic_id   MEDIUMINT UNSIGNED NOT NULL,
  PRIMARY KEY (article_id, topic_id),
  KEY idx_article_topics_topic (topic_id),
  CONSTRAINT fk_atp_article FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE CASCADE,
  CONSTRAINT fk_atp_topic   FOREIGN KEY (topic_id)   REFERENCES topics (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sources (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title        VARCHAR(255) NOT NULL,
  publisher    VARCHAR(190) NULL,
  url          VARCHAR(500) NULL,
  document_path VARCHAR(255) NULL COMMENT 'Documento original archivado',
  source_type  ENUM('documento','declaracion','medio','dato','entrevista','otro') NOT NULL DEFAULT 'otro',
  published_on DATE NULL,
  notes        VARCHAR(500) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sources_type (source_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE article_sources (
  article_id  INT UNSIGNED NOT NULL,
  source_id   INT UNSIGNED NOT NULL,
  position    SMALLINT NOT NULL DEFAULT 0,
  certainty   ENUM('confirmado','probable','disputado') NOT NULL DEFAULT 'confirmado',
  PRIMARY KEY (article_id, source_id),
  KEY idx_as_source (source_id),
  CONSTRAINT fk_as_article FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE CASCADE,
  CONSTRAINT fk_as_source  FOREIGN KEY (source_id)  REFERENCES sources (id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pregunta heredada: de que pieza y de que duda nacio esta noticia.
CREATE TABLE article_lineage (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  article_id      INT UNSIGNED NOT NULL COMMENT 'La pieza nueva',
  parent_article_id INT UNSIGNED NULL COMMENT 'La pieza de la que nacio',
  question_id     BIGINT UNSIGNED NULL COMMENT 'La duda de la audiencia que la origino',
  note            VARCHAR(500) NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_lineage_article (article_id),
  KEY idx_lineage_parent (parent_article_id),
  CONSTRAINT fk_lineage_article FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE CASCADE,
  CONSTRAINT fk_lineage_parent  FOREIGN KEY (parent_article_id) REFERENCES articles (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE article_relations (
  article_id  INT UNSIGNED NOT NULL,
  related_id  INT UNSIGNED NOT NULL,
  relation    ENUM('contexto','actualiza','contradice','continua','relacionada') NOT NULL DEFAULT 'relacionada',
  position    SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (article_id, related_id),
  KEY idx_relations_related (related_id),
  CONSTRAINT fk_rel_article FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE CASCADE,
  CONSTRAINT fk_rel_related FOREIGN KEY (related_id) REFERENCES articles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. EXPEDIENTES VIVOS, CRONOLOGIA Y ACTORES
-- ---------------------------------------------------------------------

CREATE TABLE dossiers (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid         CHAR(36) NOT NULL,
  slug         VARCHAR(190) NOT NULL,
  title        VARCHAR(255) NOT NULL,
  lead_question VARCHAR(500) NULL,
  summary      TEXT NULL,
  status       ENUM('abierto','en_seguimiento','cerrado') NOT NULL DEFAULT 'abierto',
  cover_path   VARCHAR(255) NULL,
  cover_alt    VARCHAR(255) NULL,
  topic_id     MEDIUMINT UNSIGNED NULL,
  is_demo      TINYINT(1) NOT NULL DEFAULT 0,
  opened_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dossiers_slug (slug),
  UNIQUE KEY uq_dossiers_uuid (uuid),
  KEY idx_dossiers_topic (topic_id),
  CONSTRAINT fk_dossiers_topic FOREIGN KEY (topic_id) REFERENCES topics (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE timeline_events (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dossier_id   INT UNSIGNED NULL,
  article_id   INT UNSIGNED NULL,
  occurred_on  DATE NOT NULL,
  occurred_time TIME NULL,
  title        VARCHAR(255) NOT NULL,
  detail       TEXT NULL,
  certainty    ENUM('confirmado','probable','disputado') NOT NULL DEFAULT 'confirmado',
  source_id    INT UNSIGNED NULL,
  position     SMALLINT NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_timeline_dossier (dossier_id, occurred_on),
  KEY idx_timeline_article (article_id, occurred_on),
  CONSTRAINT fk_timeline_dossier FOREIGN KEY (dossier_id) REFERENCES dossiers (id) ON DELETE CASCADE,
  CONSTRAINT fk_timeline_article FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE CASCADE,
  CONSTRAINT fk_timeline_source  FOREIGN KEY (source_id)  REFERENCES sources (id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE actors (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(160) NOT NULL,
  name        VARCHAR(190) NOT NULL,
  actor_type  ENUM('persona','institucion','empresa','colectivo','otro') NOT NULL DEFAULT 'persona',
  description VARCHAR(500) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_actors_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE article_actors (
  article_id INT UNSIGNED NOT NULL,
  actor_id   INT UNSIGNED NOT NULL,
  role_note  VARCHAR(255) NULL,
  position   SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (article_id, actor_id),
  KEY idx_aa_actor (actor_id),
  CONSTRAINT fk_aa_article FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE CASCADE,
  CONSTRAINT fk_aa_actor   FOREIGN KEY (actor_id)   REFERENCES actors (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Consecuencias para ti: familia, negocio, diaspora.
CREATE TABLE impact_profiles (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  article_id  INT UNSIGNED NOT NULL,
  profile     ENUM('familia','negocio','trabajador','diaspora','general') NOT NULL DEFAULT 'general',
  headline    VARCHAR(255) NOT NULL,
  detail      TEXT NULL,
  certainty   ENUM('confirmado','probable','disputado') NOT NULL DEFAULT 'probable',
  position    SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_impact_article (article_id, profile),
  CONSTRAINT fk_impact_article FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. MEDIOS Y VIDEO
-- ---------------------------------------------------------------------

CREATE TABLE media_assets (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid          CHAR(36) NOT NULL,
  kind          ENUM('imagen','documento','audio','subtitulo','otro') NOT NULL DEFAULT 'imagen',
  original_name VARCHAR(255) NOT NULL,
  storage_path  VARCHAR(255) NOT NULL COMMENT 'Ruta relativa dentro de public/uploads',
  original_kept_path VARCHAR(255) NULL COMMENT 'Ruta en storage/originals, fuera de public',
  mime_type     VARCHAR(120) NOT NULL,
  bytes         INT UNSIGNED NOT NULL DEFAULT 0,
  width         SMALLINT UNSIGNED NULL,
  height        SMALLINT UNSIGNED NULL,
  alt_text      VARCHAR(255) NULL,
  caption       VARCHAR(500) NULL,
  credit        VARCHAR(190) NULL,
  is_synthetic  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Imagen generada con IA. Debe rotularse.',
  uploaded_by   INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at    DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_media_uuid (uuid),
  KEY idx_media_kind (kind, created_at),
  CONSTRAINT fk_media_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE videos (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid              CHAR(36) NOT NULL,
  title             VARCHAR(255) NOT NULL,
  slug              VARCHAR(190) NOT NULL,
  description       TEXT NULL,
  video_type        ENUM('hero','resumen','entrevista','clip','live','explicacion','fondo') NOT NULL DEFAULT 'resumen',
  provider          ENUM('propio','youtube','tiktok','instagram','otro') NOT NULL DEFAULT 'propio',
  provider_video_id VARCHAR(120) NULL,
  source_url        VARCHAR(500) NULL,
  embed_url         VARCHAR(500) NULL,
  storage_path      VARCHAR(255) NULL COMMENT 'Archivo propio bajo public/uploads',
  poster_path       VARCHAR(255) NULL,
  thumbnail_path    VARCHAR(255) NULL,
  duration_seconds  INT UNSIGNED NULL,
  orientation       ENUM('horizontal','vertical','cuadrada') NOT NULL DEFAULT 'horizontal',
  aspect_ratio      VARCHAR(12) NULL COMMENT 'Ej: 16:9, 9:16, 1:1',
  captions_path     VARCHAR(255) NULL COMMENT 'WebVTT',
  transcript_status ENUM('ninguna','pendiente','borrador','revisada') NOT NULL DEFAULT 'ninguna',
  text_alternative  TEXT NULL COMMENT 'Alternativa textual si el video no esta disponible',
  bytes             INT UNSIGNED NULL,
  is_demo           TINYINT(1) NOT NULL DEFAULT 0,
  published_at      DATETIME NULL,
  status            ENUM('borrador','publicado','archivado') NOT NULL DEFAULT 'borrador',
  autoplay_allowed  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Solo hero y fondo sin audio',
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at        DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_videos_slug (slug),
  UNIQUE KEY uq_videos_uuid (uuid),
  KEY idx_videos_type (video_type, published_at),
  KEY idx_videos_status (status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE video_chapters (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  video_id     INT UNSIGNED NOT NULL,
  starts_at    INT UNSIGNED NOT NULL COMMENT 'Segundos',
  ends_at      INT UNSIGNED NULL,
  title        VARCHAR(255) NOT NULL,
  position     SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_chapters_video (video_id, starts_at),
  CONSTRAINT fk_chapters_video FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE video_transcripts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  video_id     INT UNSIGNED NOT NULL,
  language     VARCHAR(8) NOT NULL DEFAULT 'es',
  content      LONGTEXT NOT NULL COMMENT 'Texto plano, indexable por el buscador',
  segments     LONGTEXT NULL COMMENT 'JSON con marcas de tiempo',
  reviewed_by  INT UNSIGNED NULL,
  reviewed_at  DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_transcript_video_lang (video_id, language),
  CONSTRAINT fk_transcripts_video FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE,
  CONSTRAINT fk_transcripts_user FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE article_video_relations (
  article_id     INT UNSIGNED NOT NULL,
  video_id       INT UNSIGNED NOT NULL,
  editorial_role ENUM('principal','resumen','clip_vertical','explicacion','entrevista','live','contexto') NOT NULL DEFAULT 'resumen',
  position       SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (article_id, video_id),
  KEY idx_avr_video (video_id),
  CONSTRAINT fk_avr_article FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE CASCADE,
  CONSTRAINT fk_avr_video   FOREIGN KEY (video_id)   REFERENCES videos (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. LIVES
-- ---------------------------------------------------------------------

CREATE TABLE lives (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid           CHAR(36) NOT NULL,
  slug           VARCHAR(190) NOT NULL,
  title          VARCHAR(255) NOT NULL,
  lead_question  VARCHAR(500) NULL COMMENT 'Pregunta principal del programa',
  summary        TEXT NULL,
  status         ENUM('anunciado','programado','en_vivo','finalizado','cancelado') NOT NULL DEFAULT 'anunciado',
  starts_at      DATETIME NULL COMMENT 'UNICA fuente de verdad de fecha y hora, en UTC',
  ends_at        DATETIME NULL,
  timezone       VARCHAR(64) NOT NULL DEFAULT 'America/Caracas',
  guests         TEXT NULL,
  stream_url     VARCHAR(500) NULL,
  recording_video_id INT UNSIGNED NULL,
  cover_path     VARCHAR(255) NULL,
  cover_alt      VARCHAR(255) NULL,
  aftermath      TEXT NULL COMMENT 'Resumen posterior: que se respondio y que quedo abierto',
  pending_matters TEXT NULL,
  is_demo        TINYINT(1) NOT NULL DEFAULT 0,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lives_slug (slug),
  UNIQUE KEY uq_lives_uuid (uuid),
  KEY idx_lives_status (status, starts_at),
  CONSTRAINT fk_lives_recording FOREIGN KEY (recording_video_id) REFERENCES videos (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE live_articles (
  live_id    INT UNSIGNED NOT NULL,
  article_id INT UNSIGNED NOT NULL,
  moment     ENUM('antes','despues') NOT NULL DEFAULT 'antes',
  position   SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (live_id, article_id),
  KEY idx_la_article (article_id),
  CONSTRAINT fk_la_live    FOREIGN KEY (live_id)    REFERENCES lives (id)    ON DELETE CASCADE,
  CONSTRAINT fk_la_article FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. PARTICIPACION: PULSO Y PREGUNTAS
-- ---------------------------------------------------------------------

CREATE TABLE polls (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid         CHAR(36) NOT NULL,
  article_id   INT UNSIGNED NULL,
  scope        ENUM('articulo','dia','live') NOT NULL DEFAULT 'articulo',
  question     VARCHAR(500) NOT NULL,
  methodology_note VARCHAR(500) NULL COMMENT 'Metodologia visible. No es una encuesta cientifica.',
  is_open      TINYINT(1) NOT NULL DEFAULT 1,
  is_demo      TINYINT(1) NOT NULL DEFAULT 0,
  opens_at     DATETIME NULL,
  closes_at    DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_polls_uuid (uuid),
  KEY idx_polls_article (article_id),
  CONSTRAINT fk_polls_article FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE poll_options (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  poll_id   INT UNSIGNED NOT NULL,
  label     VARCHAR(190) NOT NULL,
  position  SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_options_poll (poll_id, position),
  CONSTRAINT fk_options_poll FOREIGN KEY (poll_id) REFERENCES polls (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Voto anonimo. No se guarda IP en claro, solo un hash con sal del
-- servidor, para control basico de abuso sin identificar personas.
CREATE TABLE poll_responses (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  poll_id       INT UNSIGNED NOT NULL,
  option_id     INT UNSIGNED NOT NULL,
  stage         ENUM('inicial','informado') NOT NULL DEFAULT 'inicial',
  voter_hash    CHAR(64) NOT NULL,
  session_token CHAR(32) NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_one_vote_per_stage (poll_id, voter_hash, stage),
  KEY idx_responses_poll_stage (poll_id, stage, option_id),
  KEY idx_responses_session (session_token),
  CONSTRAINT fk_responses_poll   FOREIGN KEY (poll_id)   REFERENCES polls (id)        ON DELETE CASCADE,
  CONSTRAINT fk_responses_option FOREIGN KEY (option_id) REFERENCES poll_options (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE community_questions (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  article_id   INT UNSIGNED NULL,
  live_id      INT UNSIGNED NULL,
  body         VARCHAR(1000) NOT NULL,
  author_name  VARCHAR(120) NULL COMMENT 'Opcional. Puede ser anonima.',
  contact_email VARCHAR(190) NULL COMMENT 'Solo con consentimiento explicito',
  status       ENUM('pendiente','aprobada','seleccionada','respondida','rechazada') NOT NULL DEFAULT 'pendiente',
  answer       TEXT NULL,
  answered_article_id INT UNSIGNED NULL,
  moderated_by INT UNSIGNED NULL,
  moderated_at DATETIME NULL,
  submitter_hash CHAR(64) NOT NULL,
  is_demo      TINYINT(1) NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_questions_status (status, created_at),
  KEY idx_questions_article (article_id),
  KEY idx_questions_live (live_id),
  CONSTRAINT fk_questions_article FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE SET NULL,
  CONSTRAINT fk_questions_live    FOREIGN KEY (live_id)    REFERENCES lives (id)    ON DELETE SET NULL,
  CONSTRAINT fk_questions_user    FOREIGN KEY (moderated_by) REFERENCES users (id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subscribers (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(190) NOT NULL,
  purpose       SET('resumen_semanal','avisos_live','correcciones') NOT NULL DEFAULT 'resumen_semanal',
  consent_text  VARCHAR(500) NOT NULL COMMENT 'Texto exacto que la persona acepto',
  consented_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirm_token CHAR(48) NULL,
  confirmed_at  DATETIME NULL,
  unsubscribed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subscribers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  channel      ENUM('correo','panel','push') NOT NULL DEFAULT 'panel',
  audience     ENUM('equipo','suscriptores','usuario') NOT NULL DEFAULT 'equipo',
  user_id      INT UNSIGNED NULL,
  subject      VARCHAR(255) NOT NULL,
  body         TEXT NULL,
  payload      JSON NULL,
  status       ENUM('pendiente','enviada','fallida','leida') NOT NULL DEFAULT 'pendiente',
  scheduled_for DATETIME NULL,
  sent_at      DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_status (status, scheduled_for),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. SISTEMA: PORTADA, BUSCADOR, REDIRECCIONES, AUDITORIA, ANALITICA
-- ---------------------------------------------------------------------

-- La portada SELECCIONA y ORDENA. Nunca almacena ni elimina noticias.
CREATE TABLE homepage_slots (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  zone        ENUM('hero','pregunta_dia','pulso_dia','esencial','max_analiza','conversacion','proximo_live','ultimos_videos','expedientes','preguntas') NOT NULL,
  position    SMALLINT NOT NULL DEFAULT 0,
  article_id  INT UNSIGNED NULL,
  video_id    INT UNSIGNED NULL,
  live_id     INT UNSIGNED NULL,
  dossier_id  INT UNSIGNED NULL,
  poll_id     INT UNSIGNED NULL,
  override_title VARCHAR(255) NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  updated_by  INT UNSIGNED NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_slots_zone (zone, position, is_active),
  CONSTRAINT fk_slots_article FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE CASCADE,
  CONSTRAINT fk_slots_video   FOREIGN KEY (video_id)   REFERENCES videos (id)   ON DELETE CASCADE,
  CONSTRAINT fk_slots_live    FOREIGN KEY (live_id)    REFERENCES lives (id)    ON DELETE CASCADE,
  CONSTRAINT fk_slots_dossier FOREIGN KEY (dossier_id) REFERENCES dossiers (id) ON DELETE CASCADE,
  CONSTRAINT fk_slots_poll    FOREIGN KEY (poll_id)    REFERENCES polls (id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indice desnormalizado. Incluye transcripciones de video, por lo que el
-- buscador encuentra una noticia por lo que se dijo en camara.
CREATE TABLE search_index (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_type  ENUM('articulo','video','live','expediente') NOT NULL,
  entity_id    INT UNSIGNED NOT NULL,
  article_id   INT UNSIGNED NULL COMMENT 'Articulo al que apunta el resultado, si aplica',
  title        VARCHAR(255) NOT NULL,
  summary      TEXT NULL,
  body         LONGTEXT NULL,
  transcript   LONGTEXT NULL,
  keywords     TEXT NULL COMMENT 'Etiquetas, temas, autor, categoria',
  editorial_type VARCHAR(20) NULL,
  status       VARCHAR(20) NULL,
  has_video    TINYINT(1) NOT NULL DEFAULT 0,
  live_id      INT UNSIGNED NULL,
  category_id  SMALLINT UNSIGNED NULL,
  author_id    INT UNSIGNED NULL,
  published_at DATETIME NULL,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_search_entity (entity_type, entity_id),
  KEY idx_search_published (published_at),
  KEY idx_search_filters (status, editorial_type, category_id, author_id),
  KEY idx_search_video (has_video),
  KEY idx_search_live (live_id),
  FULLTEXT KEY ft_search_all (title, summary, body, transcript, keywords),
  FULLTEXT KEY ft_search_title (title, summary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE redirects (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  from_path    VARCHAR(255) NOT NULL,
  to_path      VARCHAR(500) NOT NULL,
  status_code  SMALLINT NOT NULL DEFAULT 301,
  reason       VARCHAR(255) NULL,
  hits         INT UNSIGNED NOT NULL DEFAULT 0,
  created_by   INT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_redirects_from (from_path),
  CONSTRAINT fk_redirects_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NULL,
  user_label   VARCHAR(190) NULL COMMENT 'Se conserva aunque la cuenta desaparezca',
  action       VARCHAR(80) NOT NULL,
  entity_type  VARCHAR(40) NULL,
  entity_id    INT UNSIGNED NULL,
  summary      VARCHAR(500) NULL,
  changes      LONGTEXT NULL COMMENT 'JSON antes/despues',
  ip_hash      CHAR(64) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_entity (entity_type, entity_id, created_at),
  KEY idx_audit_user (user_id, created_at),
  KEY idx_audit_action (action, created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Analitica propia por eventos. Alimenta el Indice de Comprension y la
-- metrica norte "sesion informada completa". Sin datos personales.
CREATE TABLE analytics_events (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event         VARCHAR(48) NOT NULL COMMENT 'lectura_50, lectura_90, capa_abierta, pulso_inicial, pulso_informado, pregunta_enviada, video_iniciado',
  entity_type   VARCHAR(24) NULL,
  entity_id     INT UNSIGNED NULL,
  session_token CHAR(32) NOT NULL,
  meta          JSON NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_events_lookup (event, created_at),
  KEY idx_events_entity (entity_type, entity_id, created_at),
  KEY idx_events_session (session_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  name       VARCHAR(80) NOT NULL,
  value      TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
