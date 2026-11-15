-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: hospital_management_system
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `hospital_management_system`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `hospital_management_system` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci */;

USE `hospital_management_system`;

--
-- Table structure for table `appointments`
--

DROP TABLE IF EXISTS `appointments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `appointments` (
  `appointment_id` varchar(36) NOT NULL DEFAULT uuid(),
  `patient_id` varchar(36) NOT NULL,
  `doctor_id` varchar(36) NOT NULL,
  `appointment_datetime` datetime NOT NULL,
  `visit_reason` varchar(255) DEFAULT NULL,
  `appointment_status` enum('pending','confirmed','rejected','completed','cancelled','no_show') NOT NULL DEFAULT 'pending',
  `doctor_response` enum('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
  `doctor_reject_reason` varchar(255) DEFAULT NULL,
  `doctor_responded_at` datetime DEFAULT NULL,
  `reviewed_by` varchar(36) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `slot_key` varchar(80) GENERATED ALWAYS AS (if(`appointment_status` in ('pending','confirmed'),concat(`doctor_id`,'|',`appointment_datetime`),NULL)) STORED,
  PRIMARY KEY (`appointment_id`),
  UNIQUE KEY `uq_doctor_slot` (`slot_key`),
  KEY `fk_appointment_patient` (`patient_id`),
  KEY `fk_appointment_doctor` (`doctor_id`),
  KEY `fk_appointment_reviewer` (`reviewed_by`),
  CONSTRAINT `fk_appointment_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`doctor_id`),
  CONSTRAINT `fk_appointment_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`),
  CONSTRAINT `fk_appointment_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `chk_reject_needs_reason` CHECK (`doctor_response` <> 'rejected' or `doctor_reject_reason` is not null and char_length(trim(`doctor_reject_reason`)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointments`
--

LOCK TABLES `appointments` WRITE;
/*!40000 ALTER TABLE `appointments` DISABLE KEYS */;
/*!40000 ALTER TABLE `appointments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bills`
--

DROP TABLE IF EXISTS `bills`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bills` (
  `bill_id` varchar(36) NOT NULL DEFAULT uuid(),
  `patient_id` varchar(36) NOT NULL,
  `appointment_id` varchar(36) DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('Cash','Card','Bank Transfer') DEFAULT 'Cash',
  `payment_status` enum('Unpaid','Partially Paid','Paid','Cancelled') DEFAULT 'Unpaid',
  `bill_date` datetime DEFAULT current_timestamp(),
  `due_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`bill_id`),
  KEY `FK_Bills_Patients` (`patient_id`),
  KEY `FK_Bills_Appointments` (`appointment_id`),
  CONSTRAINT `FK_Bills_Appointments` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`),
  CONSTRAINT `FK_Bills_Patients` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`),
  CONSTRAINT `CHK_Bills_TotalAmount` CHECK (`total_amount` >= 0),
  CONSTRAINT `CHK_Bills_PaidAmount` CHECK (`paid_amount` >= 0 and `paid_amount` <= `total_amount`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bills`
--

LOCK TABLES `bills` WRITE;
/*!40000 ALTER TABLE `bills` DISABLE KEYS */;
INSERT INTO `bills` (`bill_id`, `patient_id`, `appointment_id`, `total_amount`, `paid_amount`, `payment_method`, `payment_status`, `bill_date`, `due_date`, `notes`) VALUES ('00a22845-49fa-4155-b6c9-aa0cc3ca4339','1d096138-e0ae-11f1-be17-9009dff66714',NULL,100000.00,100000.00,'Cash','Paid','2026-11-15 11:36:50','2026-11-18',NULL);
/*!40000 ALTER TABLE `bills` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `doctors`
--

DROP TABLE IF EXISTS `doctors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `doctors` (
  `doctor_id` varchar(36) NOT NULL DEFAULT uuid(),
  `user_id` varchar(36) DEFAULT NULL,
  `specialization` varchar(100) NOT NULL DEFAULT 'Internal Medicine',
  `qualification` varchar(100) DEFAULT NULL,
  `room_number` int(11) NOT NULL DEFAULT 1,
  `status` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`doctor_id`),
  KEY `fk_doctors_user_id` (`user_id`),
  CONSTRAINT `fk_doctors_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `doctors`
--

LOCK TABLES `doctors` WRITE;
/*!40000 ALTER TABLE `doctors` DISABLE KEYS */;
INSERT INTO `doctors` (`doctor_id`, `user_id`, `specialization`, `qualification`, `room_number`, `status`) VALUES ('a500f09d-dc1a-11f1-ad38-9009dff66714','14c12883-99e2-4d5a-8c9a-b13f643fc17c','Internal Medicine',NULL,1,1);
INSERT INTO `doctors` (`doctor_id`, `user_id`, `specialization`, `qualification`, `room_number`, `status`) VALUES ('f6c3a372-dd91-11f1-baa3-9009dff66714','374a18f6-d1b4-4e7f-9c10-49cc5615da7c','Internal Medicine',NULL,1,1);
/*!40000 ALTER TABLE `doctors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `medical_records`
--

DROP TABLE IF EXISTS `medical_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `medical_records` (
  `record_id` varchar(36) NOT NULL DEFAULT uuid(),
  `patient_id` varchar(36) NOT NULL,
  `doctor_id` varchar(36) NOT NULL,
  `appointment_id` varchar(36) DEFAULT NULL,
  `diagnosis` text NOT NULL,
  `symptoms` text DEFAULT NULL,
  `treatment` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `record_date` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`record_id`),
  KEY `FK_MedicalRecords_Patients` (`patient_id`),
  KEY `FK_MedicalRecords_Doctors` (`doctor_id`),
  KEY `FK_MedicalRecords_Appointments` (`appointment_id`),
  CONSTRAINT `FK_MedicalRecords_Appointments` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`),
  CONSTRAINT `FK_MedicalRecords_Doctors` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`doctor_id`),
  CONSTRAINT `FK_MedicalRecords_Patients` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `medical_records`
--

LOCK TABLES `medical_records` WRITE;
/*!40000 ALTER TABLE `medical_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `medical_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `patients`
--

DROP TABLE IF EXISTS `patients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `patients` (
  `patient_id` char(36) NOT NULL DEFAULT uuid(),
  `user_id` char(36) DEFAULT NULL,
  `dob` date DEFAULT '1999-01-01',
  `gender` enum('male','female','others') DEFAULT NULL,
  `address` varchar(255) DEFAULT 'Ha Noi, Viet Nam',
  `medical_history` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`patient_id`),
  KEY `fk_user_id` (`user_id`),
  CONSTRAINT `fk_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `patients`
--

LOCK TABLES `patients` WRITE;
/*!40000 ALTER TABLE `patients` DISABLE KEYS */;
INSERT INTO `patients` (`patient_id`, `user_id`, `dob`, `gender`, `address`, `medical_history`, `created_at`) VALUES ('1d096138-e0ae-11f1-be17-9009dff66714','19d310c6-aedf-49f4-a7d2-47886dc1fa66','1999-01-01','others','Ha Noi, Viet Nam',NULL,'2026-11-15 11:36:24');
INSERT INTO `patients` (`patient_id`, `user_id`, `dob`, `gender`, `address`, `medical_history`, `created_at`) VALUES ('c660f93e-db5e-11f1-a6d8-9009dff66714','374a18f6-d1b4-4e7f-9c10-49cc5615da7c','2005-10-12','male','Ha Noi, Viet Nam','Gay','2026-11-08 17:25:52');
/*!40000 ALTER TABLE `patients` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `prescription_details`
--

DROP TABLE IF EXISTS `prescription_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prescription_details` (
  `prescription_detailID` varchar(36) NOT NULL DEFAULT uuid(),
  `prescription_id` varchar(36) NOT NULL,
  `medicine_name` varchar(255) NOT NULL,
  `dosage` varchar(100) DEFAULT NULL,
  `frequency` varchar(100) DEFAULT NULL,
  `duration` varchar(100) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `instructions` text DEFAULT NULL,
  PRIMARY KEY (`prescription_detailID`),
  KEY `FK_PrescriptionDetails_Prescriptions` (`prescription_id`),
  CONSTRAINT `FK_PrescriptionDetails_Prescriptions` FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`prescription_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `prescription_details`
--

LOCK TABLES `prescription_details` WRITE;
/*!40000 ALTER TABLE `prescription_details` DISABLE KEYS */;
/*!40000 ALTER TABLE `prescription_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `prescriptions`
--

DROP TABLE IF EXISTS `prescriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prescriptions` (
  `prescription_id` varchar(36) NOT NULL DEFAULT uuid(),
  `record_id` varchar(36) NOT NULL,
  `patient_id` varchar(36) NOT NULL,
  `doctor_id` varchar(36) NOT NULL,
  `PrescriptionDate` datetime DEFAULT current_timestamp(),
  `Notes` text DEFAULT NULL,
  PRIMARY KEY (`prescription_id`),
  KEY `FK_Prescriptions_MedicalRecords` (`record_id`),
  KEY `FK_Prescriptions_Patients` (`patient_id`),
  KEY `FK_Prescriptions_Doctors` (`doctor_id`),
  CONSTRAINT `FK_Prescriptions_Doctors` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`doctor_id`),
  CONSTRAINT `FK_Prescriptions_MedicalRecords` FOREIGN KEY (`record_id`) REFERENCES `medical_records` (`record_id`),
  CONSTRAINT `FK_Prescriptions_Patients` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `prescriptions`
--

LOCK TABLES `prescriptions` WRITE;
/*!40000 ALTER TABLE `prescriptions` DISABLE KEYS */;
/*!40000 ALTER TABLE `prescriptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `test_results`
--

DROP TABLE IF EXISTS `test_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `test_results` (
  `test_result_id` varchar(36) NOT NULL DEFAULT uuid(),
  `patient_id` varchar(36) NOT NULL,
  `doctor_id` varchar(36) NOT NULL,
  `record_id` varchar(36) DEFAULT NULL,
  `test_name` varchar(255) NOT NULL,
  `test_date` datetime DEFAULT current_timestamp(),
  `result` text DEFAULT NULL,
  `reference_range` varchar(255) DEFAULT NULL,
  `Status` enum('Pending','Completed','Cancelled') DEFAULT 'Pending',
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`test_result_id`),
  KEY `FK_TestResults_Patients` (`patient_id`),
  KEY `FK_TestResults_Doctors` (`doctor_id`),
  KEY `FK_TestResults_MedicalRecords` (`record_id`),
  CONSTRAINT `FK_TestResults_Doctors` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`doctor_id`),
  CONSTRAINT `FK_TestResults_MedicalRecords` FOREIGN KEY (`record_id`) REFERENCES `medical_records` (`record_id`),
  CONSTRAINT `FK_TestResults_Patients` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `test_results`
--

LOCK TABLES `test_results` WRITE;
/*!40000 ALTER TABLE `test_results` DISABLE KEYS */;
/*!40000 ALTER TABLE `test_results` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `user_id` char(36) NOT NULL DEFAULT uuid(),
  `username` varchar(100) NOT NULL,
  `user_password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone_number` varchar(10) DEFAULT NULL,
  `role` enum('admin','receptionist','doctor','patient') DEFAULT NULL,
  `create_at` datetime NOT NULL DEFAULT current_timestamp(),
  `user_status` tinyint(4) DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `avatar` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` (`user_id`, `username`, `user_password`, `full_name`, `email`, `phone_number`, `role`, `create_at`, `user_status`, `is_deleted`, `avatar`) VALUES ('14c12883-99e2-4d5a-8c9a-b13f643fc17c','quan','$2y$10$dH/mWj02kznBpLe5wJcrjussj1Cm/TbnNWZdwFuU7BopWpQdR6Y1m','Minh Quân Nguyễn','qn98518@gmail.com','1234567890','doctor','2026-11-09 15:50:42',1,0,NULL);
INSERT INTO `users` (`user_id`, `username`, `user_password`, `full_name`, `email`, `phone_number`, `role`, `create_at`, `user_status`, `is_deleted`, `avatar`) VALUES ('19d310c6-aedf-49f4-a7d2-47886dc1fa66','vudz','$2y$10$dLDv8Nc2XHTlMI0NYUvuHOp65m0BreWOKVDYbvXL/eTpMcxKWcgP.','Minh Ngọc Vũ','closer2007vn@gmail.com','1234567890','patient','2026-11-15 11:36:24',0,1,NULL);
INSERT INTO `users` (`user_id`, `username`, `user_password`, `full_name`, `email`, `phone_number`, `role`, `create_at`, `user_status`, `is_deleted`, `avatar`) VALUES ('22363b68-da7a-11f1-9e88-9009dff66714','Closeramv','$2y$10$jIb9fg1gFd6XSkuE0jq1/umGi7N1VS/vo2xg.nrp3KCerF9FlMLOm','Minh Quân Nguyễn','qn98518@gmail.com','1234567890','admin','2026-11-07 14:09:12',0,0,NULL);
INSERT INTO `users` (`user_id`, `username`, `user_password`, `full_name`, `email`, `phone_number`, `role`, `create_at`, `user_status`, `is_deleted`, `avatar`) VALUES ('374a18f6-d1b4-4e7f-9c10-49cc5615da7c','namdz','$2y$10$3DWxzuu3PcXDQ.iNTSvvP.7b8pJT0ZOovIvMK0hp3ue3VPwLBP.8q','Nguyễn Hà Nam','nam@gmail.com','1234567890','doctor','2026-11-08 17:25:52',1,0,NULL);
INSERT INTO `users` (`user_id`, `username`, `user_password`, `full_name`, `email`, `phone_number`, `role`, `create_at`, `user_status`, `is_deleted`, `avatar`) VALUES ('61340cfd-da82-11f1-9e88-9009dff66714','admin','$2y$10$.50M.AoLfWkmUOPPvNGbFuoTGNPRSJFW2cLpldpacQeqDs3DnYXGW','Administrator','qn98518@gmail.com','1234567890','admin','2026-11-07 15:08:13',1,0,'e0e2cde18385bcd7f70eccc5ae8ce1d1.jpg');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'hospital_management_system'
--

--
-- Dumping routines for database 'hospital_management_system'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-15 14:35:53
