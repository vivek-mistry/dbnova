-- mysqldump --databases shop crm: two databases in one file.
CREATE DATABASE /*!32312 IF NOT EXISTS*/ `shop`;
USE `shop`;
CREATE TABLE `orders` (`id` int NOT NULL, PRIMARY KEY (`id`));
INSERT INTO `orders` VALUES (1);

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `crm`;
USE `crm`;
CREATE TABLE `contacts` (`id` int NOT NULL, PRIMARY KEY (`id`));
INSERT INTO `contacts` VALUES (1);
