-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 20-11-2026 a las 23:50:53
-- Versión del servidor: 10.11.16-MariaDB
-- Versión de PHP: 8.5.12

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
  `nombre` varchar(100) NOT NULL,
  `apellidos` varchar(100) NOT NULL,
  `telefono` varchar(20) NOT NULL,
  `fecha_nacimiento` date NOT NULL,
  `fecha_ingreso` date NOT NULL,
  `estatus` tinyint(1) NOT NULL,
  `id_cinturon` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_spanish_ci;

--
-- Volcado de datos para la tabla `alumnos`
--

INSERT INTO `alumnos` (`id_alumno`, `nombre`, `apellidos`, `telefono`, `fecha_nacimiento`, `fecha_ingreso`, `estatus`, `id_cinturon`) VALUES
(0, 'Melany', 'Guerrero', '88888888', '2004-09-24', '2026-10-30', 1, NULL),
(1, 'Prueba', 'RF', '99999999', '2000-01-01', '2026-11-19', 1, NULL),
(2, 'Panel', 'Alumno', '99999999', '2001-01-01', '2026-11-19', 1, NULL),
(3, 'Panel', 'Alumno', '99999999', '2001-01-01', '2026-11-19', 1, NULL),
(4, 'Panel', 'Alumno', '99999999', '2001-01-01', '2026-11-19', 1, NULL),
(5, 'Director', 'Prueba', '8681210225', '2000-05-23', '2026-11-20', 1, NULL),
(6, 'Secretaria', 'Prueba', '8681215235', '1998-12-01', '2026-11-20', 1, NULL),
(7, 'alumno', 'prueba', '8681543586', '2001-03-25', '2026-11-20', 1, NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `asistencias`
--

CREATE TABLE `asistencias` (
  `id_asistencia` int(20) NOT NULL,
  `id_alumno` int(20) NOT NULL,
  `id_clase` int(20) NOT NULL,
  `fecha` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cinturon`
--

CREATE TABLE `cinturon` (
  `id_cinturon` int(11) NOT NULL,
  `color` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_spanish_ci;

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
  `capacidad` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `pagos`
--

CREATE TABLE `pagos` (
  `id_pago` int(50) NOT NULL,
  `id_alumno` int(20) NOT NULL,
  `concepto` varchar(100) NOT NULL,
  `monto` decimal(10,2) NOT NULL,
  `fecha_pago` date NOT NULL,
  `metodo_pago` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `profesor`
--

CREATE TABLE `profesor` (
  `id_profesor` int(20) NOT NULL,
  `nombre` varchar(50) NOT NULL,
  `apellidos` varchar(100) NOT NULL,
  `telefono` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_spanish_ci;

--
-- Volcado de datos para la tabla `profesor`
--

INSERT INTO `profesor` (`id_profesor`, `nombre`, `apellidos`, `telefono`) VALUES
(0, 'Alexis', 'Hernandez', '88888888'),
(1, 'Carlos', 'Ramírez López', '8681000000');

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_spanish_ci;

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`id_usuario`, `correo`, `password`, `rol`, `id_alumno`, `id_profesor`, `activo`, `nombre`, `apellidos`, `telefono`, `fecha_nacimiento`) VALUES
(1, 'admin@localhost.com', '$2y$12$D.d01Q948oiP1.H5aT1ZAOvQi3uUhcLcLlajjG0lwizFB5ASjz/0i', 'administrador', NULL, NULL, 1, NULL, NULL, NULL, NULL),
(2, 'director@localhost.com', '$2y$12$NL35RxC8S5Bx.uaBY.4iIezr0VuR3YFiqpDDDAPmk2PIomeN2NacW', 'alumno', 5, NULL, 1, NULL, NULL, NULL, NULL),
(3, 'sevcretaria@localhost.com', '$2y$12$EBrAAjQbi9LWyX15wkf1TuH/aHq6IPlBFKKyHWM2hZ17Hz8JB89uu', 'alumno', 6, NULL, 1, NULL, NULL, NULL, NULL),
(4, 'alumno@localhost.com', '$2y$12$h58.WPl0ZVjWDu7SdzREcOVTA/Rbsfh7rCLD7oPFGiH8.a86dqLNu', 'alumno', 7, NULL, 1, NULL, NULL, NULL, NULL),
(5, 'instructor.ejemplo@localhost.com', '$2y$12$5qGMf6f4DtEzqsT05LBW4OFQXIGKtfjgfZK.V2YLexMzIsr3dH8iC', 'maestro', NULL, 1, 1, NULL, NULL, NULL, NULL),
(6, 'secretaria.ejemplo@localhost.com', '$2y$12$JHn2dk6yhfbbplee1TV8Ru3N/kDELZdcEFBS4EhqIbYP0OhXGOSLm', 'recepcionista', NULL, NULL, 1, 'María', 'Hernández Ruiz', '8681000001', NULL),
(7, 'director.ejemplo@localhost.com', '$2y$12$ri7B0lL5dQnRv9aJ5BE/FOkJhpGs9M5a9B.IC/HOtxZHHXZ25YlcO', 'director', NULL, NULL, 1, 'Jorge', 'Salinas Cruz', '8681000002', NULL);

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `alumnos`
--
ALTER TABLE `alumnos`
  ADD PRIMARY KEY (`id_alumno`),
  ADD KEY `id_cinturon` (`id_cinturon`);

--
-- Indices de la tabla `asistencias`
--
ALTER TABLE `asistencias`
  ADD PRIMARY KEY (`id_asistencia`),
  ADD KEY `id_alumno` (`id_alumno`,`id_clase`),
  ADD KEY `id_clase` (`id_clase`);

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
  ADD KEY `id_profesor` (`id_profesor`);

--
-- Indices de la tabla `pagos`
--
ALTER TABLE `pagos`
  ADD PRIMARY KEY (`id_pago`),
  ADD KEY `id_alumno` (`id_alumno`);

--
-- Indices de la tabla `profesor`
--
ALTER TABLE `profesor`
  ADD PRIMARY KEY (`id_profesor`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id_usuario`),
  ADD KEY `id_alumno` (`id_alumno`),
  ADD KEY `id_profesor` (`id_profesor`);

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `asistencias`
--
ALTER TABLE `asistencias`
  ADD CONSTRAINT `asistencias_ibfk_1` FOREIGN KEY (`id_clase`) REFERENCES `clases` (`id_clase`),
  ADD CONSTRAINT `asistencias_ibfk_2` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`);

--
-- Filtros para la tabla `cinturon`
--
ALTER TABLE `cinturon`
  ADD CONSTRAINT `cinturon_ibfk_1` FOREIGN KEY (`id_cinturon`) REFERENCES `alumnos` (`id_cinturon`);

--
-- Filtros para la tabla `clases`
--
ALTER TABLE `clases`
  ADD CONSTRAINT `clases_ibfk_1` FOREIGN KEY (`id_profesor`) REFERENCES `profesor` (`id_profesor`);

--
-- Filtros para la tabla `pagos`
--
ALTER TABLE `pagos`
  ADD CONSTRAINT `pagos_ibfk_1` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`);

--
-- Filtros para la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD CONSTRAINT `usuarios_ibfk_1` FOREIGN KEY (`id_profesor`) REFERENCES `profesor` (`id_profesor`) ON DELETE SET NULL,
  ADD CONSTRAINT `usuarios_ibfk_2` FOREIGN KEY (`id_alumno`) REFERENCES `alumnos` (`id_alumno`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
