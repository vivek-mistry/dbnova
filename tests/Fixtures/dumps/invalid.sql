CREATE TABLE `items` (
  `id` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

INSERT INTO `items` VALUES (1),(2);

-- The next statement is not valid SQL (line 9).
INSERT INTO `items` VALUES (3,;

INSERT INTO `items` VALUES (4);
