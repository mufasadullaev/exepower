-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: May 18, 2025 at 04:21 PM
-- Server version: 8.0.30
-- PHP Version: 8.1.9

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `exepower`
--

-- --------------------------------------------------------

--
-- Table structure for table `equipment`
--

CREATE TABLE `equipment` (
  `id` int NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type_id` int NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `equipment`
--

INSERT INTO `equipment` (`id`, `name`, `type_id`, `description`) VALUES
(1, 'Блок ТГ7', 1, 'Турбогенератор блок 7'),
(2, 'Блок ТГ8', 1, 'Турбогенератор блок 8'),
(3, 'ГТ 1', 2, 'Газовая установка 1'),
(4, 'ПТ 1', 2, 'Паровая установка 1'),
(5, 'ГТ 2', 2, 'Газовая установка 2'),
(6, 'ПТ 2', 2, 'Паровая установка 2');

-- --------------------------------------------------------

--
-- Table structure for table `equipment_events`
--

CREATE TABLE `equipment_events` (
  `id` int NOT NULL,
  `equipment_id` int NOT NULL,
  `event_type` enum('pusk','ostanov') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_time` datetime NOT NULL,
  `shift_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `reason_id` int DEFAULT NULL,
  `comment` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `equipment_tool_events`
--

CREATE TABLE `equipment_tool_events` (
  `id` int NOT NULL,
  `equipment_id` int NOT NULL,
  `tool_type` enum('evaporator','aos') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` enum('on','off') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_time` datetime NOT NULL,
  `shift_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `comment` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `equipment_types`
--

CREATE TABLE `equipment_types` (
  `id` int NOT NULL,
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `equipment_types`
--

INSERT INTO `equipment_types` (`id`, `name`, `description`) VALUES
(1, 'ТГ', 'Турбогенератор (Блок)'),
(2, 'ПГУ', 'Парогазовая установка');

-- --------------------------------------------------------

--
-- Table structure for table `functions`
--

CREATE TABLE `functions` (
  `id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `symbol` varchar(50) NOT NULL,
  `unit` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `functions`
--

INSERT INTO `functions` (`id`, `name`, `symbol`, `unit`) VALUES
(1, 'На изменение cosφГТ', 'MW', 'ΔNcosφгт'),
(2, 'На изменение cosφПТ', 'MW', 'ΔNcosφпт'),
(5, 'На изменение температуры мокрого термометра в градирню', 'MW', 'ΔNбл'),
(6, 'На  давление топливного газа', 'MW', 'ΔNРт.газа'),
(7, 'На температуру топливного газа', 'MW', 'ΔNtт.газа'),
(8, 'На температуру окружающей среды и относительную влажность (применяется в Вкл условии испар. охл.)', '-', 'ktн.в'),
(9, 'На температуру на входе компрессора (применяется в Откл условии испар. охл.)', '-', 'ktвх.к'),
(10, 'На изменение барометрического давления и температуры на входе компрессора (применяется при условии Вкл и Откл испар. охл.)', '-', 'kРбар'),
(11, 'На относительную влажность и температуру на входе компрессора (применяется при условии Откл испар. охл.)', '-', 'kφвх.к'),
(12, 'На отклонение низшей теплоты сгорания топлива', '-', 'kQнр'),
(13, 'На изменение частоты ГТ (применяется при условии Вкл и Откл испар. охл.)', '-', 'kν'),
(14, 'Кривая деградации ГТУ', '-', 'kd'),
(15, 'Исходно-номинальный УРТ ПГУ, g/kWh', '', 'bэ(ином)пгу'),
(16, 'На занос КВОУ', '-', 'kΔNквоу'),
(17, 'На включение антиобледенительной системы (АОС)', '-', 'kΔNаос');

-- --------------------------------------------------------

--
-- Table structure for table `function_coefficients`
--

CREATE TABLE `function_coefficients` (
  `id` int NOT NULL,
  `coeff_set_id` int NOT NULL,
  `coeff_index` tinyint NOT NULL,
  `coeff_value` varchar(60) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `function_coefficients`
--

INSERT INTO `function_coefficients` (`id`, `coeff_set_id`, `coeff_index`, `coeff_value`) VALUES
(1, 2, 0, '1755.2900390625'),
(2, 2, 1, '-3.205090045928955'),
(3, 2, 2, '0.05486280098557472'),
(4, 2, 3, '-0.00021166099759284407'),
(5, 2, 4, '0.0000003550269980223675'),
(6, 3, 0, '1657.9200439453125'),
(7, 3, 1, '1.1666500568389893'),
(8, 3, 2, '0.0057877302169799805'),
(9, 3, 3, '0.000034862001484725624'),
(10, 3, 4, '-0.000000054505498781054484'),
(11, 4, 0, '1604.8399658203125'),
(12, 4, 1, '2.3590800762176514'),
(13, 4, 2, '0.003359379945322871'),
(14, 4, 3, '0.000029723600164288655'),
(15, 4, 4, '-0.000000012745999811158981'),
(16, 5, 0, '1555.1800537109375'),
(17, 5, 1, '4.974629878997803'),
(18, 5, 2, '-0.029911700636148453'),
(19, 5, 3, '0.00021919200662523508'),
(20, 5, 4, '-0.00000034930599213112146'),
(21, 6, 0, '1143.06005859375'),
(22, 6, 1, '-26.166799545288086'),
(23, 6, 2, '0.8006089925765991'),
(24, 6, 3, '-0.009191659279167652'),
(25, 6, 4, '0.000038746598875150084'),
(26, 7, 0, '823.3779907226562'),
(27, 7, 1, '-0.5977259874343872'),
(28, 7, 2, '0.1083339974284172'),
(29, 7, 3, '-0.0011761400382965803'),
(30, 7, 4, '0.00000580712003284134'),
(31, 8, 0, '503.6910095214844'),
(32, 8, 1, '24.971399307250977'),
(33, 8, 2, '-0.5839400291442871'),
(34, 8, 3, '0.0068393899127841'),
(35, 8, 4, '-0.000027132400646223687'),
(36, 9, 0, '184.00399780273438'),
(37, 9, 1, '50.54050064086914'),
(38, 9, 2, '-1.2762099504470825'),
(39, 9, 3, '0.014854899607598782'),
(40, 9, 4, '-0.00006007189949741587'),
(41, 10, 0, '-2258.969970703125'),
(42, 10, 1, '181.37899780273438'),
(43, 10, 2, '-1.2210999727249146'),
(44, 10, 3, '0.7589390277862549'),
(45, 10, 4, '-0.027979999780654907'),
(46, 11, 0, '11709.099609375'),
(47, 11, 1, '-2784.800048828125'),
(48, 11, 2, '246.26100158691406'),
(49, 11, 3, '-9.73622989654541'),
(50, 11, 4, '0.14245499670505524'),
(51, 12, 0, '-45.964298248291016'),
(52, 12, 1, '4.827079772949219'),
(53, 12, 2, '-0.05074400082230568'),
(54, 12, 3, '0.0032291701063513756'),
(55, 12, 4, '-0.00004613100099959411'),
(56, 13, 0, '0.9689080119132996'),
(57, 13, 1, '0.0003156570019200444'),
(58, 13, 2, '0.00014696399739477783'),
(59, 13, 3, '-0.000005482860160554992'),
(60, 13, 4, '0.00000009937119926917148'),
(61, 14, 0, '0.9599990248680115'),
(62, 14, 1, '0.002653149887919426'),
(63, 14, 2, '-0.00003143159847240895'),
(64, 14, 3, '0.0000013571500403486425'),
(65, 14, 4, '-0.00000002131999998766787'),
(66, 15, 0, '0.9515349864959717'),
(67, 15, 1, '0.005012880079448223'),
(68, 15, 2, '-0.0002166699996450916'),
(69, 15, 3, '0.000009106630386668257'),
(70, 15, 4, '-0.0000001673389959933047'),
(71, 16, 0, '1.0010199546813965'),
(72, 16, 1, '-0.005077649839222431'),
(73, 16, 2, '0.0005469620227813721'),
(74, 16, 3, '-0.000015791099940543063'),
(75, 16, 4, '0.00000020399799893766613'),
(76, 17, 0, '0.9417200088500977'),
(77, 17, 1, '0.010191399604082108'),
(78, 17, 2, '-0.0007922369986772537'),
(79, 17, 3, '0.000040521499613532797'),
(80, 17, 4, '-0.0000009228569979313761'),
(81, 18, 0, '0.971930980682373'),
(82, 18, 1, '0.0037383600138127804'),
(83, 18, 2, '-0.00012977700680494308'),
(84, 18, 3, '0.00001051520030159736'),
(85, 18, 4, '-0.0000003104970005551877'),
(86, 19, 0, '0.9779049754142761'),
(87, 19, 1, '0.0008858800283633173'),
(88, 19, 2, '0.00013807200593873858'),
(89, 19, 3, '0.0000005849850026606873'),
(90, 19, 4, '-0.00000014898699873810983'),
(91, 20, 0, '5.1519598960876465'),
(92, 20, 1, '-0.010167700238525867'),
(93, 20, 2, '0.0000096167696028715'),
(94, 20, 3, '-0.000000004335249936815444'),
(95, 20, 4, '0.0000000000007381049981283749'),
(96, 21, 0, '11.614399909973145'),
(97, 21, 1, '-0.03615809977054596'),
(98, 21, 2, '0.00004875849845120683'),
(99, 21, 3, '-0.00000003050169894436294'),
(100, 21, 4, '0.000000000007290790367264766'),
(101, 22, 0, '12.050999641418457'),
(102, 22, 1, '-0.037794001400470734'),
(103, 22, 2, '0.00005103430157760158'),
(104, 22, 3, '-0.00000003189540009884695'),
(105, 22, 4, '0.000000000007607960268718461'),
(106, 23, 0, '10.117899894714355'),
(107, 23, 1, '-0.03013090044260025'),
(108, 23, 2, '0.00003964930147049017'),
(109, 23, 3, '-0.000000024381899166314724'),
(110, 23, 4, '0.0000000000057494898599019795'),
(111, 24, 0, '-0.4032599925994873'),
(112, 24, 1, '0.011545100249350071'),
(113, 24, 2, '-0.000022243999410420656'),
(114, 24, 3, '0.000000016455500073675466'),
(115, 24, 4, '-0.0000000000043493902056324085'),
(116, 25, 0, '3.6476099491119385'),
(117, 25, 1, '-0.004994669929146767'),
(118, 25, 2, '0.0000030867399800627027'),
(119, 25, 3, '-0.0000000007819629943561779'),
(120, 25, 4, '0.00000000000004619649931689174'),
(121, 26, 0, '1.001039981842041'),
(122, 26, 1, '-0.00001184859956993023'),
(123, 26, 2, '-0.000000008912640403480054'),
(124, 26, 3, '0.0000000011128300503315813'),
(125, 26, 4, '-0.0000000000185371006738988'),
(126, 27, 0, '1.0027600526809692'),
(127, 27, 1, '-0.00002991129986185115'),
(128, 27, 2, '-0.00000002004549948253498'),
(129, 27, 3, '0.0000000009597610484135544'),
(130, 27, 4, '-0.000000000014456599979617568'),
(131, 28, 0, '1.0059399604797363'),
(132, 28, 1, '-0.00006159880285849795'),
(133, 28, 2, '-0.00000023280199457076378'),
(134, 28, 3, '0.000000006190369994385492'),
(135, 28, 4, '-0.00000000006904200128277083'),
(136, 29, 0, '1.0061700344085693'),
(137, 29, 1, '-0.00006967419903958216'),
(138, 29, 2, '0.000000021887300860612413'),
(139, 29, 3, '0.000000000676119993325841'),
(140, 29, 4, '-0.000000000011072399941358668'),
(141, 30, 0, '1.018470048904419'),
(142, 30, 1, '-0.00023955899814609438'),
(143, 30, 2, '0.0000010818699820447364'),
(144, 30, 3, '-0.000000010547499762481038'),
(145, 30, 4, '0.0000000000292165007464984'),
(146, 31, 0, '1.0210800170898438'),
(147, 31, 1, '-0.0003951030084863305'),
(148, 31, 2, '0.000006046930138836615'),
(149, 31, 3, '-0.00000010144700013370311'),
(150, 31, 4, '0.0000000008339530177536858'),
(151, 32, 0, '0.9475730061531067'),
(152, 32, 1, '0.0000016474399444632581'),
(153, 32, 2, '-0.000000000011708399874632569'),
(154, 33, 0, '0.9499959945678711'),
(155, 33, 1, '0.000001576480030962557'),
(156, 33, 2, '-0.000000000010949199880094795'),
(157, 34, 0, '0.949386'),
(158, 34, 1, '0.00000163193'),
(159, 34, 2, '-0.00000000001152942'),
(160, 35, 0, '164.85800170898438'),
(161, 35, 1, '-489.6239929199219'),
(162, 35, 2, '487.01300048828125'),
(163, 35, 3, '-161.2480010986328'),
(164, 36, 0, '133.80099487304688'),
(165, 36, 1, '-392.1960144042969'),
(166, 36, 2, '385.5429992675781'),
(167, 36, 3, '-126.14800262451172'),
(168, 37, 0, '27.14929962158203'),
(169, 37, 1, '-68.68309783935547'),
(170, 37, 2, '58.860801696777344'),
(171, 37, 3, '-16.32699966430664'),
(172, 38, 0, '42.0625'),
(173, 38, 1, '-113.06600189208984'),
(174, 38, 2, '102.91899871826172'),
(175, 38, 3, '-3.0915699005126953'),
(176, 39, 0, '287.2489929199219'),
(177, 39, 1, '-836.5789794921875'),
(178, 39, 2, '815.3889770507812'),
(179, 39, 3, '-265.0589904785156'),
(180, 40, 0, '443.9630126953125'),
(181, 40, 1, '-1305.8199462890625'),
(182, 40, 2, '1284.52001953125'),
(183, 40, 3, '-421.6629943847656'),
(184, 41, 0, '0.00474285714'),
(185, 41, 1, '0.000547085714'),
(186, 41, 2, '-0.0000000498285714'),
(187, 41, 3, '0.000000000001792'),
(188, 42, 0, '1.614'),
(189, 42, 1, '0.0000786285714'),
(190, 42, 2, '-0.0000000010857143'),
(191, 43, 0, '0.99026'),
(192, 43, 1, '0.00000974'),
(193, 44, 0, '1.00003698'),
(194, 44, 1, '0.00502014479'),
(195, 44, 2, '-0.00001124809'),
(196, 45, 0, '448.5652770027'),
(197, 45, 1, '-2.1475024881'),
(198, 45, 2, '0.007231743'),
(199, 45, 3, '-0.0000082402'),
(200, 46, 0, '0.2109403744'),
(201, 46, 1, '742.3358617859');

-- --------------------------------------------------------

--
-- Table structure for table `function_coeff_sets`
--

CREATE TABLE `function_coeff_sets` (
  `id` int NOT NULL,
  `function_id` int NOT NULL,
  `x_value` double NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `function_coeff_sets`
--

INSERT INTO `function_coeff_sets` (`id`, `function_id`, `x_value`, `created_at`) VALUES
(2, 1, 1, '2025-05-09 03:55:58'),
(3, 1, 0.95, '2025-05-09 03:55:58'),
(4, 1, 0.9, '2025-05-09 03:55:58'),
(5, 1, 0.85, '2025-05-09 03:55:58'),
(6, 2, 1, '2025-05-09 03:55:58'),
(7, 2, 0.95, '2025-05-09 03:55:58'),
(8, 2, 0.9, '2025-05-09 03:55:58'),
(9, 2, 0.85, '2025-05-09 03:55:58'),
(10, 5, 0, '2025-05-09 03:55:58'),
(11, 6, 0, '2025-05-09 03:55:58'),
(12, 7, 0, '2025-05-09 03:55:58'),
(13, 8, 10, '2025-05-09 03:55:58'),
(14, 8, 20, '2025-05-09 03:55:58'),
(15, 8, 40, '2025-05-09 03:55:58'),
(16, 8, 58, '2025-05-09 03:55:58'),
(17, 8, 80, '2025-05-09 03:55:58'),
(18, 8, 100, '2025-05-09 03:55:58'),
(19, 9, 0, '2025-05-09 03:55:58'),
(20, 10, -15, '2025-05-09 03:55:58'),
(21, 10, -3, '2025-05-09 03:55:58'),
(22, 10, 9, '2025-05-09 03:55:58'),
(23, 10, 10, '2025-05-09 03:55:58'),
(24, 10, 33, '2025-05-09 03:55:58'),
(25, 10, 45, '2025-05-09 03:55:58'),
(26, 11, -15, '2025-05-09 03:55:58'),
(27, 11, -3, '2025-05-09 03:55:58'),
(28, 11, 9, '2025-05-09 03:55:58'),
(29, 11, 10, '2025-05-09 03:55:58'),
(30, 11, 33, '2025-05-09 03:55:58'),
(31, 11, 45, '2025-05-09 03:55:58'),
(32, 12, 4, '2025-05-09 03:55:58'),
(33, 12, 3.911, '2025-05-09 03:55:58'),
(34, 12, 3.829, '2025-05-09 03:55:58'),
(35, 13, -15, '2025-05-09 03:55:58'),
(36, 13, -3, '2025-05-09 03:55:58'),
(37, 13, 9, '2025-05-09 03:55:58'),
(38, 13, 10, '2025-05-09 03:55:58'),
(39, 13, 33, '2025-05-09 03:55:58'),
(40, 13, 45, '2025-05-09 03:55:58'),
(41, 14, 10000, '2025-05-09 03:55:58'),
(42, 14, 10001, '2025-05-09 03:55:58'),
(43, 16, 0, '2025-05-09 03:55:58'),
(44, 17, 0, '2025-05-09 03:55:58'),
(45, 15, 212.31, '2025-05-09 03:55:58'),
(46, 15, 212.3, '2025-05-09 03:55:58');

-- --------------------------------------------------------

--
-- Table structure for table `meters`
--

CREATE TABLE `meters` (
  `id` int NOT NULL,
  `meter_type_id` int NOT NULL,
  `equipment_id` int NOT NULL,
  `serial_number` varchar(50) NOT NULL,
  `scale` decimal(12,3) NOT NULL,
  `coefficient_k` decimal(10,3) NOT NULL DEFAULT '1.000',
  `name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `meter_readings`
--

CREATE TABLE `meter_readings` (
  `id` bigint NOT NULL,
  `meter_id` int NOT NULL,
  `date` date NOT NULL,
  `r0` decimal(14,3) NOT NULL,
  `r8` decimal(14,3) DEFAULT NULL,
  `r16` decimal(14,3) DEFAULT NULL,
  `r24` decimal(14,3) DEFAULT NULL,
  `shift1` decimal(14,3) DEFAULT NULL,
  `shift2` decimal(14,3) DEFAULT NULL,
  `shift3` decimal(14,3) DEFAULT NULL,
  `total` decimal(14,3) DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `meter_reading_history`
--

CREATE TABLE `meter_reading_history` (
  `history_id` bigint NOT NULL,
  `reading_id` bigint NOT NULL,
  `old_r0` decimal(14,3) DEFAULT NULL,
  `old_r8` decimal(14,3) DEFAULT NULL,
  `old_r16` decimal(14,3) DEFAULT NULL,
  `old_r24` decimal(14,3) DEFAULT NULL,
  `new_r0` decimal(14,3) DEFAULT NULL,
  `new_r8` decimal(14,3) DEFAULT NULL,
  `new_r16` decimal(14,3) DEFAULT NULL,
  `new_r24` decimal(14,3) DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `comment` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `meter_replacements`
--

CREATE TABLE `meter_replacements` (
  `id` bigint NOT NULL,
  `meter_id` int NOT NULL,
  `replacement_date` date NOT NULL,
  `replacement_time` time NOT NULL,
  `old_serial` varchar(50) NOT NULL,
  `old_coefficient` decimal(10,4) NOT NULL,
  `old_scale` decimal(12,3) NOT NULL,
  `old_reading` decimal(14,3) NOT NULL,
  `new_serial` varchar(50) NOT NULL,
  `new_coefficient` decimal(10,4) NOT NULL,
  `new_scale` decimal(12,3) NOT NULL,
  `new_reading` decimal(14,3) NOT NULL,
  `downtime_min` int NOT NULL DEFAULT '0',
  `power_mw` decimal(8,3) NOT NULL DEFAULT '0.000',
  `user_id` int DEFAULT NULL,
  `comment` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `meter_types`
--

CREATE TABLE `meter_types` (
  `id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `meter_types`
--

INSERT INTO `meter_types` (`id`, `name`, `description`) VALUES
(1, 'Выработка электроэнергии', 'Учёт выработанной энергии'),
(2, 'Расход на собственные нужды', 'Учёт расхода на собственные нужды станции'),
(3, 'Тиристорные', 'Учёт тиристорных возбудителей'),
(4, 'Расход на хозяйственные нужды', 'Учёт расхода на хозяйственные нужды');

-- --------------------------------------------------------

--
-- Table structure for table `parameters`
--

CREATE TABLE `parameters` (
  `id` int NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `unit` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `equipment_type_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `parameter_values`
--

CREATE TABLE `parameter_values` (
  `id` int NOT NULL,
  `parameter_id` int NOT NULL,
  `equipment_id` int NOT NULL,
  `value` decimal(10,2) NOT NULL,
  `date` date NOT NULL,
  `shift_id` int NOT NULL,
  `user_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shifts`
--

CREATE TABLE `shifts` (
  `id` int NOT NULL,
  `name` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `shifts`
--

INSERT INTO `shifts` (`id`, `name`, `start_time`, `end_time`) VALUES
(1, 'Смена 1', '00:00:00', '07:59:59'),
(2, 'Смена 2', '08:00:00', '15:59:59'),
(3, 'Смена 3', '16:00:00', '23:59:59');

-- --------------------------------------------------------

--
-- Table structure for table `start_reasons`
--

CREATE TABLE `start_reasons` (
  `id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `start_reasons`
--

INSERT INTO `start_reasons` (`id`, `name`, `description`) VALUES
(1, 'Холодный', 'Пуск оборудования из холодного состояния'),
(2, 'Неостывший', 'Пуск оборудования из неостывшего состояния'),
(3, 'Горячий', 'Пуск оборудования из горячего состояния');

-- --------------------------------------------------------

--
-- Table structure for table `stop_reasons`
--

CREATE TABLE `stop_reasons` (
  `id` int NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `stop_reasons`
--

INSERT INTO `stop_reasons` (`id`, `name`, `description`) VALUES
(1, 'аварийный', 'Вынужденный останов из-за аварии'),
(2, 'плановый', 'Плановая остановка для ТО'),
(3, 'резерв', 'Перевод оборудования в резерв');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('рядовой','инженер','менеджер') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'рядовой',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `role`, `created_at`, `updated_at`) VALUES
(1, 'admin', '$2y$10$XFE/UQoRJkZsxZWGlPQWIeNI1UtNQ36LNH1BIjTjAZALYWQe.uBVm', 'менеджер', '2025-05-08 21:58:52', '2025-05-08 21:58:52');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `equipment`
--
ALTER TABLE `equipment`
  ADD PRIMARY KEY (`id`),
  ADD KEY `type_id` (`type_id`);

--
-- Indexes for table `equipment_events`
--
ALTER TABLE `equipment_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `shift_id` (`shift_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `reason_id` (`reason_id`),
  ADD KEY `idx_eq_time` (`equipment_id`,`event_time`);

--
-- Indexes for table `equipment_tool_events`
--
ALTER TABLE `equipment_tool_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_equipment_tool` (`equipment_id`,`tool_type`),
  ADD KEY `idx_equipment_time` (`equipment_id`,`event_time`),
  ADD KEY `shift_id` (`shift_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `equipment_types`
--
ALTER TABLE `equipment_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `functions`
--
ALTER TABLE `functions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `function_coefficients`
--
ALTER TABLE `function_coefficients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_set_index` (`coeff_set_id`,`coeff_index`),
  ADD KEY `idx_set` (`coeff_set_id`);

--
-- Indexes for table `function_coeff_sets`
--
ALTER TABLE `function_coeff_sets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_func_x` (`function_id`,`x_value`),
  ADD KEY `idx_func` (`function_id`);

--
-- Indexes for table `meters`
--
ALTER TABLE `meters`
  ADD PRIMARY KEY (`id`),
  ADD KEY `meter_type_id` (`meter_type_id`),
  ADD KEY `equipment_id` (`equipment_id`);

--
-- Indexes for table `meter_readings`
--
ALTER TABLE `meter_readings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_reading_meter_date` (`meter_id`,`date`),
  ADD KEY `meter_id` (`meter_id`);

--
-- Indexes for table `meter_reading_history`
--
ALTER TABLE `meter_reading_history`
  ADD PRIMARY KEY (`history_id`),
  ADD KEY `reading_id` (`reading_id`);

--
-- Indexes for table `meter_replacements`
--
ALTER TABLE `meter_replacements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `meter_id` (`meter_id`);

--
-- Indexes for table `meter_types`
--
ALTER TABLE `meter_types`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `parameters`
--
ALTER TABLE `parameters`
  ADD PRIMARY KEY (`id`),
  ADD KEY `equipment_type_id` (`equipment_type_id`);

--
-- Indexes for table `parameter_values`
--
ALTER TABLE `parameter_values`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_parameter_value` (`parameter_id`,`equipment_id`,`date`,`shift_id`),
  ADD KEY `equipment_id` (`equipment_id`),
  ADD KEY `shift_id` (`shift_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `shifts`
--
ALTER TABLE `shifts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `start_reasons`
--
ALTER TABLE `start_reasons`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `stop_reasons`
--
ALTER TABLE `stop_reasons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `equipment`
--
ALTER TABLE `equipment`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `equipment_events`
--
ALTER TABLE `equipment_events`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=286;

--
-- AUTO_INCREMENT for table `equipment_tool_events`
--
ALTER TABLE `equipment_tool_events`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=77;

--
-- AUTO_INCREMENT for table `equipment_types`
--
ALTER TABLE `equipment_types`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `functions`
--
ALTER TABLE `functions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `function_coefficients`
--
ALTER TABLE `function_coefficients`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=202;

--
-- AUTO_INCREMENT for table `function_coeff_sets`
--
ALTER TABLE `function_coeff_sets`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `meters`
--
ALTER TABLE `meters`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `meter_readings`
--
ALTER TABLE `meter_readings`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `meter_reading_history`
--
ALTER TABLE `meter_reading_history`
  MODIFY `history_id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `meter_replacements`
--
ALTER TABLE `meter_replacements`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `meter_types`
--
ALTER TABLE `meter_types`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `parameters`
--
ALTER TABLE `parameters`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `parameter_values`
--
ALTER TABLE `parameter_values`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `shifts`
--
ALTER TABLE `shifts`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `start_reasons`
--
ALTER TABLE `start_reasons`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `stop_reasons`
--
ALTER TABLE `stop_reasons`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `equipment`
--
ALTER TABLE `equipment`
  ADD CONSTRAINT `equipment_ibfk_1` FOREIGN KEY (`type_id`) REFERENCES `equipment_types` (`id`);

--
-- Constraints for table `equipment_events`
--
ALTER TABLE `equipment_events`
  ADD CONSTRAINT `equipment_events_ibfk_1` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `equipment_events_ibfk_2` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`),
  ADD CONSTRAINT `equipment_events_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `equipment_events_ibfk_4` FOREIGN KEY (`reason_id`) REFERENCES `stop_reasons` (`id`);

--
-- Constraints for table `equipment_tool_events`
--
ALTER TABLE `equipment_tool_events`
  ADD CONSTRAINT `equipment_tool_events_ibfk_1` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `equipment_tool_events_ibfk_2` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`),
  ADD CONSTRAINT `equipment_tool_events_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `function_coefficients`
--
ALTER TABLE `function_coefficients`
  ADD CONSTRAINT `fk_coeffs_coeffset` FOREIGN KEY (`coeff_set_id`) REFERENCES `function_coeff_sets` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `function_coeff_sets`
--
ALTER TABLE `function_coeff_sets`
  ADD CONSTRAINT `fk_coeffsets_function` FOREIGN KEY (`function_id`) REFERENCES `functions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `meters`
--
ALTER TABLE `meters`
  ADD CONSTRAINT `fk_meters_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_meters_type` FOREIGN KEY (`meter_type_id`) REFERENCES `meter_types` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `meter_readings`
--
ALTER TABLE `meter_readings`
  ADD CONSTRAINT `fk_readings_meter` FOREIGN KEY (`meter_id`) REFERENCES `meters` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `meter_reading_history`
--
ALTER TABLE `meter_reading_history`
  ADD CONSTRAINT `fk_history_reading` FOREIGN KEY (`reading_id`) REFERENCES `meter_readings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `meter_replacements`
--
ALTER TABLE `meter_replacements`
  ADD CONSTRAINT `fk_repl_meter` FOREIGN KEY (`meter_id`) REFERENCES `meters` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `parameters`
--
ALTER TABLE `parameters`
  ADD CONSTRAINT `parameters_ibfk_1` FOREIGN KEY (`equipment_type_id`) REFERENCES `equipment_types` (`id`);

--
-- Constraints for table `parameter_values`
--
ALTER TABLE `parameter_values`
  ADD CONSTRAINT `parameter_values_ibfk_1` FOREIGN KEY (`parameter_id`) REFERENCES `parameters` (`id`),
  ADD CONSTRAINT `parameter_values_ibfk_2` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`),
  ADD CONSTRAINT `parameter_values_ibfk_3` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`),
  ADD CONSTRAINT `parameter_values_ibfk_4` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
