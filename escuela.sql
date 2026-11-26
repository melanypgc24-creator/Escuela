-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 26-11-2026 a las 07:56:51
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `escuela`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `alumnos`
--

CREATE TABLE `alumnos` (
  `id_alumno` int(20) NOT NULL,
  `matricula` varchar(20) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `apellidos` varchar(100) NOT NULL,
  `telefono` varchar(20) NOT NULL,
  `correo_contacto` varchar(150) DEFAULT NULL,
  `direccion` varchar(255) DEFAULT NULL,
  `fecha_nacimiento` date NOT NULL,
  `categoria` enum('infantil','adulta') DEFAULT NULL,
  `contacto_emergencia_nombre` varchar(150) DEFAULT NULL,
  `contacto_emergencia_telefono` varchar(20) DEFAULT NULL,
  `fecha_ingreso` date NOT NULL,
  `estatus` tinyint(1) NOT NULL,
  `id_cinturon` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `alumnos`
--

INSERT INTO `alumnos` (`id_alumno`, `matricula`, `nombre`, `apellidos`, `telefono`, `correo_contacto`, `direccion`, `fecha_nacimiento`, `categoria`, `contacto_emergencia_nombre`, `contacto_emergencia_telefono`, `fecha_ingreso`, `estatus`, `id_cinturon`) VALUES
(1, 'TC-000001', 'Secretaria', 'Prueba', '8681000000', NULL, NULL, '2000-01-01', 'adulta', NULL, NULL, '2026-11-25', 0, NULL),
(2, 'TC-000002', 'Alumno', 'Prueba', '8681000000', NULL, NULL, '2001-01-01', 'adulta', NULL, NULL, '2026-11-25', 1, NULL),
(3, 'TC-000003', 'Melany', 'Guerrero', '8681000000', NULL, NULL, '2004-09-24', 'adulta', NULL, NULL, '2026-11-25', 1, NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `aplicaciones_pagos`
--

CREATE TABLE `aplicaciones_pagos` (
  `id_aplicacion` int(11) NOT NULL,
  `id_pago` int(50) NOT NULL,
  `id_cargo` int(11) NOT NULL,
  `id_alumno` int(20) NOT NULL,
  `importe_aplicado` decimal(10,2) NOT NULL,
  `aplicada_en` datetime NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `asistencias`
--

CREATE TABLE `asistencias` (
  `id_asistencia` int(20) NOT NULL,
  `id_alumno` int(20) NOT NULL,
  `id_clase` int(20) NOT NULL,
  `fecha` date NOT NULL,
  `hora` time DEFAULT NULL,
  `id_usuario_registra` int(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `auditoria_eventos`
--

CREATE TABLE `auditoria_eventos` (
  `id_evento` bigint(20) NOT NULL,
  `id_usuario` int(20) NOT NULL,
  `accion` varchar(80) NOT NULL,
  `entidad` varchar(80) NOT NULL,
  `id_entidad` varchar(64) NOT NULL,
  `detalles` text DEFAULT NULL,
  `fecha_hora` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `auditoria_eventos`
--

INSERT INTO `auditoria_eventos` (`id_evento`, `id_usuario`, `accion`, `entidad`, `id_entidad`, `detalles`, `fecha_hora`) VALUES
(1, 1, 'generar', 'cargos_mensuales', '1', '{\"periodo\":\"2026-11\",\"id_alumno\":3,\"importe\":\"1800.00\",\"fecha_vencimiento\":\"2026-11-10\",\"individual\":true}', '2026-11-25 19:10:12'),
(2, 1, 'modificar', 'cargos_mensuales', '1', '{\"vencimiento_anterior\":\"2026-11-10\",\"vencimiento_nuevo\":\"2026-11-10\"}', '2026-11-25 19:11:05'),
(3, 1, 'generar', 'cargos_mensuales', '2', '{\"periodo\":\"2026-11\",\"id_alumno\":2,\"importe\":\"180.00\",\"concepto\":\"clase_individual\",\"fecha_vencimiento\":\"2026-11-10\",\"individual\":true}', '2026-11-26 00:14:49'),
(4, 2, 'crear', 'clases', '1', '{\"id_profesor\":1,\"capacidad\":10}', '2026-11-26 00:24:42');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cargos_mensuales`
--

CREATE TABLE `cargos_mensuales` (
  `id_cargo` int(11) NOT NULL,
  `id_alumno` int(20) NOT NULL,
  `periodo` char(7) NOT NULL,
  `concepto` enum('mensualidad','clase','clase_individual','otro') NOT NULL DEFAULT 'mensualidad',
  `id_tarifa` int(11) NOT NULL,
  `categoria` enum('infantil','adulta') NOT NULL,
  `importe_tarifa` decimal(10,2) NOT NULL,
  `porcentaje` decimal(5,2) NOT NULL,
  `importe` decimal(10,2) NOT NULL,
  `fecha_vencimiento` date DEFAULT NULL,
  `estado` enum('pendiente','pagado','vencido') NOT NULL DEFAULT 'pendiente',
  `generado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `id_usuario_generador` int(20) NOT NULL,
  `clave_mensualidad` varchar(32) GENERATED ALWAYS AS (if(`concepto` = 'mensualidad',concat(`id_alumno`,'-',`periodo`),NULL)) STORED
) ;

--
-- Volcado de datos para la tabla `cargos_mensuales`
--

INSERT INTO `cargos_mensuales` (`id_cargo`, `id_alumno`, `periodo`, `concepto`, `id_tarifa`, `categoria`, `importe_tarifa`, `porcentaje`, `importe`, `fecha_vencimiento`, `estado`, `generado_en`, `id_usuario_generador`) VALUES
(1, 3, '2026-11', 'mensualidad', 2, 'adulta', 1800.00, 100.00, 1800.00, '2026-11-10', 'vencido', '2026-11-25 19:10:12', 1),
(2, 2, '2026-11', 'clase_individual', 2, 'adulta', 180.00, 100.00, 180.00, '2026-11-10', 'vencido', '2026-11-26 00:14:49', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cinturon`
--

CREATE TABLE `cinturon` (
  `id_cinturon` int(11) NOT NULL,
  `color` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `cinturon`
--

INSERT INTO `cinturon` (`id_cinturon`, `color`) VALUES
(1, 'Blanco'),
(2, 'Azul'),
(3, 'Morado'),
(4, 'Marrón'),
(5, 'Negro');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `clases`
--

CREATE TABLE `clases` (
  `id_clase` int(20) NOT NULL,
  `nombre_clase` varchar(100) NOT NULL,
  `id_profesor` int(20) NOT NULL,
  `dias` varchar(50) NOT NULL,
  `hora_inicio` time NOT NULL,
  `hora_fin` time NOT NULL,
  `capacidad` int(11) NOT NULL,
  `costo` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `clases`
--

INSERT INTO `clases` (`id_clase`, `nombre_clase`, `id_profesor`, `dias`, `hora_inicio`, `hora_fin`, `capacidad`, `costo`) VALUES
(1, 'Clase especial', 1, 'sabado', '20:00:00', '21:00:00', 10, 0.00);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `clases_alumnos`
--

CREATE TABLE `clases_alumnos` (
  `id_inscripcion` int(11) NOT NULL,
  `id_clase` int(20) NOT NULL,
  `id_alumno` int(20) NOT NULL,
  `fecha_inscripcion` date NOT NULL,
  `fecha_baja` date DEFAULT NULL,
  `estado` enum('activa','inactiva') NOT NULL DEFAULT 'activa',
  `id_alumno_activo` int(20) GENERATED ALWAYS AS (if(`estado` = 'activa',`id_alumno`,NULL)) STORED,
  `id_usuario_registra` int(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `clases_privadas`
--

CREATE TABLE `clases_privadas` (
  `id_sesion` int(20) NOT NULL,
  `id_profesor` int(20) NOT NULL,
  `fecha_hora` datetime NOT NULL,
  `precio_unitario` decimal(10,2) NOT NULL,
  `cantidad_participantes` int(11) UNSIGNED NOT NULL,
  `total_historico` decimal(10,2) NOT NULL,
  `observaciones` text DEFAULT NULL,
  `id_usuario_registra` int(20) NOT NULL
) ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `clases_privadas_alumnos`
--

CREATE TABLE `clases_privadas_alumnos` (
  `id_participante` int(11) NOT NULL,
  `id_sesion` int(20) NOT NULL,
  `id_alumno` int(20) DEFAULT NULL,
  `nombre_participante` varchar(200) NOT NULL,
  `precio_historico` decimal(10,2) NOT NULL
) ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `configuracion_mensualidades`
--

CREATE TABLE `configuracion_mensualidades` (
  `id_configuracion` int(11) NOT NULL,
  `dia_vencimiento` tinyint(3) UNSIGNED DEFAULT NULL,
  `activa` tinyint(1) NOT NULL DEFAULT 0,
  `configurada_por` int(20) DEFAULT NULL,
  `creada_en` datetime NOT NULL DEFAULT current_timestamp(),
  `configuracion_activa` tinyint(4) GENERATED ALWAYS AS (if(`activa` = 1,1,NULL)) STORED
) ;

--
-- Volcado de datos para la tabla `configuracion_mensualidades`
--

INSERT INTO `configuracion_mensualidades` (`id_configuracion`, `dia_vencimiento`, `activa`, `configurada_por`, `creada_en`) VALUES
(1, NULL, 1, NULL, '2026-11-25 17:34:27');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `historial_pagos`
--

CREATE TABLE `historial_pagos` (
  `id_historial` int(11) NOT NULL,
  `id_pago` int(50) NOT NULL,
  `estado_anterior` enum('activo','cancelado') DEFAULT NULL,
  `estado_nuevo` enum('activo','cancelado') DEFAULT NULL,
  `importe_anterior` decimal(10,2) DEFAULT NULL,
  `importe_nuevo` decimal(10,2) DEFAULT NULL,
  `id_usuario_responsable` int(20) NOT NULL,
  `fecha_hora` datetime NOT NULL DEFAULT current_timestamp(),
  `motivo` varchar(255) NOT NULL
) ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `inscripciones_clases`
--

CREATE TABLE `inscripciones_clases` (
  `id_inscripcion` int(20) NOT NULL,
  `id_alumno` int(20) NOT NULL,
  `id_clase` int(20) NOT NULL,
  `estado` enum('pendiente','aceptada','rechazada') NOT NULL DEFAULT 'pendiente',
  `fecha_solicitud` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_resolucion` datetime DEFAULT NULL,
  `revisado_por_profesor` int(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `migraciones`
--

CREATE TABLE `migraciones` (
  `id_migracion` varchar(191) NOT NULL,
  `archivo` varchar(255) NOT NULL,
  `descripcion` varchar(255) NOT NULL,
  `checksum` char(64) NOT NULL,
  `ejecutada_en` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `migraciones_estado`
--

CREATE TABLE `migraciones_estado` (
  `id_migracion` varchar(191) NOT NULL,
  `checksum` char(64) NOT NULL,
  `estado` varchar(16) NOT NULL,
  `detalle` text DEFAULT NULL,
  `iniciado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `notificaciones`
--

CREATE TABLE `notificaciones` (
  `id_notificacion` int(11) NOT NULL,
  `id_usuario_destinatario` int(20) NOT NULL,
  `id_cargo` int(11) NOT NULL,
  `tipo` enum('vencimiento_3_dias','vencimiento_hoy','cargo_cubierto') NOT NULL,
  `fecha_creacion` datetime NOT NULL DEFAULT current_timestamp(),
  `leida` tinyint(1) NOT NULL DEFAULT 0,
  `estado` enum('activa','cerrada') NOT NULL DEFAULT 'activa'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `pagos`
--

CREATE TABLE `pagos` (
  `id_pago` int(50) NOT NULL,
  `id_alumno` int(20) DEFAULT NULL,
  `concepto` varchar(100) NOT NULL,
  `monto` decimal(10,2) NOT NULL,
  `fecha_pago` date NOT NULL,
  `metodo_pago` varchar(50) NOT NULL,
  `folio` varchar(32) DEFAULT NULL,
  `tipo_pago` enum('mensualidad','clase_privada','otro') DEFAULT NULL,
  `estado` enum('activo','cancelado') DEFAULT NULL,
  `nombre_pagador` varchar(200) DEFAULT NULL,
  `id_alumno_pagador` int(20) DEFAULT NULL,
  `id_usuario_registra` int(20) DEFAULT NULL,
  `id_sesion_privada` int(20) DEFAULT NULL,
  `sesion_privada_activa` int(20) GENERATED ALWAYS AS (if(`tipo_pago` = 'clase_privada' and `estado` = 'activo',`id_sesion_privada`,NULL)) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `profesor`
--

CREATE TABLE `profesor` (
  `id_profesor` int(20) NOT NULL,
  `nombre` varchar(50) NOT NULL,
  `apellidos` varchar(100) NOT NULL,
  `telefono` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `profesor`
--

INSERT INTO `profesor` (`id_profesor`, `nombre`, `apellidos`, `telefono`) VALUES
(1, 'Instructor', 'Ejemplo', '8681000000');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tarifas_mensuales`
--

CREATE TABLE `tarifas_mensuales` (
  `id_tarifa` int(11) NOT NULL,
  `categoria` enum('infantil','adulta') NOT NULL,
  `importe` decimal(10,2) NOT NULL,
  `vigente_desde` date DEFAULT NULL,
  `vigente_hasta` date DEFAULT NULL,
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  `categoria_activa` varchar(16) GENERATED ALWAYS AS (if(`activa` = 1,`categoria`,NULL)) STORED,
  `id_usuario_crea` int(20) DEFAULT NULL,
  `creada_en` datetime NOT NULL DEFAULT current_timestamp()
) ;

--
-- Volcado de datos para la tabla `tarifas_mensuales`
--

INSERT INTO `tarifas_mensuales` (`id_tarifa`, `categoria`, `importe`, `vigente_desde`, `vigente_hasta`, `activa`, `id_usuario_crea`, `creada_en`) VALUES
(1, 'infantil', 900.00, NULL, NULL, 1, NULL, '2026-11-25 17:34:27'),
(2, 'adulta', 1800.00, NULL, NULL, 1, NULL, '2026-11-25 17:34:27');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id_usuario` int(20) NOT NULL,
  `correo` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `rol` enum('administrador','director','maestro','recepcionista','alumno') NOT NULL,
  `id_alumno` int(20) DEFAULT NULL,
  `id_profesor` int(20) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `nombre` varchar(100) DEFAULT NULL,
  `apellidos` varchar(100) DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `fecha_nacimiento` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`id_usuario`, `correo`, `password`, `rol`, `id_alumno`, `id_profesor`, `activo`, `nombre`, `apellidos`, `telefono`, `fecha_nacimiento`) VALUES
(1, 'admin@localhost.com', '$2y$10$86MqxYGfhQtr/Ic8/mttNu/fJixDcll2WPODSZcHbuQRaWFTTuCri', 'administrador', NULL, NULL, 1, NULL, NULL, NULL, NULL),
(2, 'director@localhost.com', '$2y$10$RvWAvGkjsFmq190oEaocs.OS8AmkBN1hVv0z66EurBDcuTs4aoCv.', 'director', NULL, NULL, 1, NULL, NULL, NULL, NULL),
(3, 'secretaria@localhost.com', '$2y$10$1qFK.VpKlYbmAqU8FcAOR.To0jeu2JEumK/lTsfA25WEeiJMJI.MO', 'recepcionista', NULL, NULL, 1, 'Secretaria', 'Prueba', '8681215235', NULL),
(4, 'alumno@localhost.com', '$2b$10$C2RIHL7jhT8JfkFq5Dlsp.r0LV3agJ1kwXTEaiw3928izF8ZZSs/O', 'alumno', 2, NULL, 1, NULL, NULL, NULL, NULL),
(5, 'instructor.ejemplo@localhost.com', '$2y$10$iIHNXaGGDjByWb45tq8tFut6LNHj6/p2qAEIMfXUioV8U6JiQYlge', 'maestro', NULL, 1, 1, NULL, NULL, NULL, NULL),
(6, 'secretaria.ejemplo@localhost.com', '$2b$10$EISNxR9Cw.bi1ToPFbmVJ.CdXvjeqlRxhu.DSlSpaeuizYCi2zzhe', 'recepcionista', NULL, NULL, 1, 'María', 'Hernández Ruiz', '8681000001', NULL),
(7, 'director.ejemplo@localhost.com', '$2b$10$316ViSz6mPly9.PWIhzV6e.stw3rYugb/62cOX.IC4R9wHOQGV2Yi', 'director', NULL, NULL, 1, 'Jorge', 'Salinas Cruz', '8681000002', NULL),
(8, 'melanypgc24@gmail.com', '$2y$10$.F47EaoXBjmaVa0pcFvsrOz4RJVSuqbJUHak71chOqJFFkZeEH3vu', 'alumno', 3, NULL, 1, NULL, NULL, NULL, NULL);

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `alumnos`
--
ALTER TABLE `alumnos`
  ADD PRIMARY KEY (`id_alumno`),
  ADD UNIQUE KEY `uq_alumnos_matricula` (`matricula`),
  ADD KEY `idx_alumnos_cinturon` (`id_cinturon`);

--
-- Indices de la tabla `aplicaciones_pagos`
--
ALTER TABLE `aplicaciones_pagos`
  ADD PRIMARY KEY (`id_aplicacion`),
  ADD UNIQUE KEY `uq_aplicacion_pago_cargo` (`id_pago`,`id_cargo`),
  ADD KEY `idx_aplicaciones_cargo_alumno` (`id_cargo`,`id_alumno`),
  ADD KEY `idx_aplicaciones_pago_alumno` (`id_pago`,`id_alumno`);

--
-- Indices de la tabla `asistencias`
--
ALTER TABLE `asistencias`
  ADD PRIMARY KEY (`id_asistencia`),
  ADD UNIQUE KEY `uq_asistencia_alumno_clase_fecha` (`id_alumno`,`id_clase`,`fecha`),
  ADD KEY `idx_asistencias_clase` (`id_clase`),
  ADD KEY `idx_asistencias_usuario` (`id_usuario_registra`);

--
-- Indices de la tabla `auditoria_eventos`
--
ALTER TABLE `auditoria_eventos`
  ADD PRIMARY KEY (`id_evento`),
  ADD KEY `idx_auditoria_entidad` (`entidad`,`id_entidad`,`fecha_hora`),
  ADD KEY `idx_auditoria_usuario_fecha` (`id_usuario`,`fecha_hora`);

--
-- Indices de la tabla `cargos_mensuales`
--
ALTER TABLE `cargos_mensuales`
  ADD PRIMARY KEY (`id_cargo`),
  ADD UNIQUE KEY `uq_cargo_id_alumno` (`id_cargo`,`id_alumno`),
  ADD UNIQUE KEY `uq_cargo_mensualidad` (`clave_mensualidad`),
  ADD KEY `idx_cargos_periodo_estado` (`periodo`,`estado`),
  ADD KEY `idx_cargos_vencimiento` (`fecha_vencimiento`,`estado`),
  ADD KEY `fk_cargo_tarifa` (`id_tarifa`),
  ADD KEY `fk_cargo_usuario` (`id_usuario_generador`),
  ADD KEY `idx_cargo_alumno_periodo` (`id_alumno`,`periodo`);

--
-- Indices de la tabla `cinturon`
--
ALTER TABLE `cinturon`
  ADD PRIMARY KEY (`id_cinturon`);

--
-- Indices de la tabla `clases`
--
ALTER TABLE `clases`
  ADD PRIMARY KEY (`id_clase`),
  ADD KEY `idx_clases_profesor` (`id_profesor`);

--
-- Indices de la tabla `clases_alumnos`
--
ALTER TABLE `clases_alumnos`
  ADD PRIMARY KEY (`id_inscripcion`),
  ADD UNIQUE KEY `uq_clase_alumno_activo` (`id_clase`,`id_alumno_activo`),
  ADD KEY `idx_clases_alumnos_alumno_estado` (`id_alumno`,`estado`),
  ADD KEY `fk_clase_alumno_usuario` (`id_usuario_registra`);

--
-- Indices de la tabla `clases_privadas`
--
ALTER TABLE `clases_privadas`
  ADD PRIMARY KEY (`id_sesion`),
  ADD KEY `idx_clases_privadas_fecha` (`fecha_hora`),
  ADD KEY `fk_clase_privada_profesor` (`id_profesor`),
  ADD KEY `fk_clase_privada_usuario` (`id_usuario_registra`);

--
-- Indices de la tabla `clases_privadas_alumnos`
--
ALTER TABLE `clases_privadas_alumnos`
  ADD PRIMARY KEY (`id_participante`),
  ADD KEY `idx_privadas_participante_sesion` (`id_sesion`),
  ADD KEY `idx_privadas_participante_alumno` (`id_alumno`);

--
-- Indices de la tabla `configuracion_mensualidades`
--
ALTER TABLE `configuracion_mensualidades`
  ADD PRIMARY KEY (`id_configuracion`),
  ADD UNIQUE KEY `uq_configuracion_mensual_activa` (`configuracion_activa`),
  ADD KEY `fk_configuracion_mensual_usuario` (`configurada_por`);

--
-- Indices de la tabla `historial_pagos`
--
ALTER TABLE `historial_pagos`
  ADD PRIMARY KEY (`id_historial`),
  ADD KEY `idx_historial_pago_fecha` (`id_pago`,`fecha_hora`),
  ADD KEY `fk_historial_usuario` (`id_usuario_responsable`);

--
-- Indices de la tabla `inscripciones_clases`
--
ALTER TABLE `inscripciones_clases`
  ADD PRIMARY KEY (`id_inscripcion`),
  ADD UNIQUE KEY `uq_alumno_clase` (`id_alumno`,`id_clase`),
  ADD KEY `idx_clase_estado` (`id_clase`,`estado`),
  ADD KEY `fk_insc_profesor` (`revisado_por_profesor`);

--
-- Indices de la tabla `migraciones`
--
ALTER TABLE `migraciones`
  ADD PRIMARY KEY (`id_migracion`);

--
-- Indices de la tabla `migraciones_estado`
--
ALTER TABLE `migraciones_estado`
  ADD PRIMARY KEY (`id_migracion`);

--
-- Indices de la tabla `notificaciones`
--
ALTER TABLE `notificaciones`
  ADD PRIMARY KEY (`id_notificacion`),
  ADD UNIQUE KEY `uq_notificacion_cargo_tipo` (`id_cargo`,`tipo`),
  ADD KEY `idx_notificaciones_destinatario_estado` (`id_usuario_destinatario`,`estado`,`leida`,`fecha_creacion`);

--
-- Indices de la tabla `pagos`
--
ALTER TABLE `pagos`
  ADD PRIMARY KEY (`id_pago`),
  ADD UNIQUE KEY `uq_pagos_folio` (`folio`),
  ADD UNIQUE KEY `uq_pagos_id_alumno` (`id_pago`,`id_alumno`),
  ADD UNIQUE KEY `uq_pago_privado_activo` (`sesion_privada_activa`),
  ADD KEY `idx_pagos_alumno` (`id_alumno`),
  ADD KEY `idx_pagos_tipo_estado_fecha` (`tipo_pago`,`estado`,`fecha_pago`),
  ADD KEY `fk_pagos_alumno_pagador` (`id_alumno_pagador`),
  ADD KEY `fk_pagos_usuario_registra` (`id_usuario_registra`),
  ADD KEY `fk_pagos_sesion_privada` (`id_sesion_privada`);

--
-- Indices de la tabla `profesor`
--
ALTER TABLE `profesor`
  ADD PRIMARY KEY (`id_profesor`);

--
-- Indices de la tabla `tarifas_mensuales`
--
ALTER TABLE `tarifas_mensuales`
  ADD PRIMARY KEY (`id_tarifa`),
  ADD UNIQUE KEY `uq_tarifa_categoria_activa` (`categoria_activa`),
  ADD KEY `idx_tarifa_vigencia` (`categoria`,`vigente_desde`,`vigente_hasta`),
  ADD KEY `fk_tarifa_usuario` (`id_usuario_crea`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id_usuario`),
  ADD UNIQUE KEY `uq_usuarios_correo` (`correo`),
  ADD KEY `idx_usuarios_alumno` (`id_alumno`),
  ADD KEY `idx_usuarios_profesor` (`id_profesor`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `alumnos`
--
ALTER TABLE `alumnos`
  MODIFY `id_alumno` int(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `aplicaciones_pagos`
--
ALTER TABLE `aplicaciones_pagos`
  MODIFY `id_aplicacion` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `asistencias`
--
ALTER TABLE `asistencias`
  MODIFY `id_asistencia` int(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `auditoria_eventos`
--
ALTER TABLE `auditoria_eventos`
  MODIFY `id_evento` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de la tabla `cargos_mensuales`
--
ALTER TABLE `cargos_mensuales`
  MODIFY `id_cargo` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `cinturon`
--
ALTER TABLE `cinturon`
  MODIFY `id_cinturon` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `clases`
--
ALTER TABLE `clases`
  MODIFY `id_clase` int(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `clases_alumnos`
--
ALTER TABLE `clases_alumnos`
  MODIFY `id_inscripcion` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `clases_privadas`
--
ALTER TABLE `clases_privadas`
  MODIFY `id_sesion` int(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `clases_privadas_alumnos`
--
ALTER TABLE `clases_privadas_alumnos`
  MODIFY `id_participante` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `configuracion_mensualidades`
--
ALTER TABLE `configuracion_mensualidades`
  MODIFY `id_configuracion` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `historial_pagos`
--
ALTER TABLE `historial_pagos`
  MODIFY `id_historial` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `inscripciones_clases`
--
ALTER TABLE `inscripciones_clases`
  MODIFY `id_inscripcion` int(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `notificaciones`
--
ALTER TABLE `notificaciones`
  MODIFY `id_notificacion` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `pagos`
--
ALTER TABLE `pagos`
  MODIFY `id_pago` int(50) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `profesor`
--
ALTER TABLE `profesor`
  MODIFY `id_profesor` int(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `tarifas_mensuales`
--
ALTER TABLE `tarifas_mensuales`
  MODIFY `id_tarifa` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id_usuario` int(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `alumnos`
--
ALTER TABLE `alumnos`
  ADD CONSTRAINT `fk_alumnos_cinturon` FOREIGN KEY (`id_cinturon`) REFERENCES `cinturon` (`id_cinturon`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Filtros para la tabla `aplicaciones_pagos`
--
ALTER TABLE `aplicaciones_pagos`
  ADD CONSTRAINT `fk_aplicacion_cargo_alumno` FOREIGN KEY (`id_cargo`,`id_alumno`) REFERENCES `cargos_mensuales` (`id_cargo`, `id_alumno`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_aplicacion_pago_alumno` FOREIGN KEY (`id_pago`,`id_alumno`) REFERENCES `pagos` (`id_pago`, `id_alumno`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `asistencias`
--
ALTER TABLE `asistencias`
  ADD CONSTRAINT `fk_asistencia_usuario_registra` FOREIGN KEY (`id_usuario_registra`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Filtros para la tabla `auditoria_eventos`
--
ALTER TABLE `auditoria_eventos`
  ADD CONSTRAINT `fk_auditoria_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `cargos_mensuales`
--
ALTER TABLE `cargos_mensuales`
  ADD CONSTRAINT `fk_cargo_alumno` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cargo_tarifa` FOREIGN KEY (`id_tarifa`) REFERENCES `tarifas_mensuales` (`id_tarifa`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cargo_usuario` FOREIGN KEY (`id_usuario_generador`) REFERENCES `usuarios` (`id_usuario`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `clases_alumnos`
--
ALTER TABLE `clases_alumnos`
  ADD CONSTRAINT `fk_clase_alumno_alumno` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`),
  ADD CONSTRAINT `fk_clase_alumno_clase` FOREIGN KEY (`id_clase`) REFERENCES `clases` (`id_clase`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_clase_alumno_usuario` FOREIGN KEY (`id_usuario_registra`) REFERENCES `usuarios` (`id_usuario`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `clases_privadas`
--
ALTER TABLE `clases_privadas`
  ADD CONSTRAINT `fk_clase_privada_profesor` FOREIGN KEY (`id_profesor`) REFERENCES `profesor` (`id_profesor`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_clase_privada_usuario` FOREIGN KEY (`id_usuario_registra`) REFERENCES `usuarios` (`id_usuario`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `clases_privadas_alumnos`
--
ALTER TABLE `clases_privadas_alumnos`
  ADD CONSTRAINT `fk_privada_participante_alumno` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_privada_participante_sesion` FOREIGN KEY (`id_sesion`) REFERENCES `clases_privadas` (`id_sesion`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `configuracion_mensualidades`
--
ALTER TABLE `configuracion_mensualidades`
  ADD CONSTRAINT `fk_configuracion_mensual_usuario` FOREIGN KEY (`configurada_por`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Filtros para la tabla `historial_pagos`
--
ALTER TABLE `historial_pagos`
  ADD CONSTRAINT `fk_historial_pago` FOREIGN KEY (`id_pago`) REFERENCES `pagos` (`id_pago`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_historial_usuario` FOREIGN KEY (`id_usuario_responsable`) REFERENCES `usuarios` (`id_usuario`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `notificaciones`
--
ALTER TABLE `notificaciones`
  ADD CONSTRAINT `fk_notificacion_cargo` FOREIGN KEY (`id_cargo`) REFERENCES `cargos_mensuales` (`id_cargo`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_notificacion_usuario` FOREIGN KEY (`id_usuario_destinatario`) REFERENCES `usuarios` (`id_usuario`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `pagos`
--
ALTER TABLE `pagos`
  ADD CONSTRAINT `fk_pagos_alumno_pagador` FOREIGN KEY (`id_alumno_pagador`) REFERENCES `alumnos` (`id_alumno`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pagos_sesion_privada` FOREIGN KEY (`id_sesion_privada`) REFERENCES `clases_privadas` (`id_sesion`),
  ADD CONSTRAINT `fk_pagos_usuario_registra` FOREIGN KEY (`id_usuario_registra`) REFERENCES `usuarios` (`id_usuario`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `tarifas_mensuales`
--
ALTER TABLE `tarifas_mensuales`
  ADD CONSTRAINT `fk_tarifa_usuario` FOREIGN KEY (`id_usuario_crea`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
