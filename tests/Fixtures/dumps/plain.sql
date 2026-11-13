-- A dump without CREATE DATABASE or USE: everything goes into the chosen database.
SET NAMES utf8mb4;

DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `products` VALUES (1,'Widget'),(2,'Gadget; deluxe'),(3,'Doohickey "quoted"');
