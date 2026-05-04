ALTER TABLE `profile`
    ADD COLUMN IF NOT EXISTS `site_title_en` varchar(50) DEFAULT NULL AFTER `site_title`,
    ADD COLUMN IF NOT EXISTS `hero_role_en` varchar(100) DEFAULT NULL AFTER `hero_role`,
    ADD COLUMN IF NOT EXISTS `bio_en` text DEFAULT NULL AFTER `bio`,
    ADD COLUMN IF NOT EXISTS `availability_text_en` varchar(100) DEFAULT NULL AFTER `availability_text`,
    ADD COLUMN IF NOT EXISTS `projects_desc_en` text DEFAULT NULL AFTER `projects_desc`,
    ADD COLUMN IF NOT EXISTS `contact_desc_en` text DEFAULT NULL AFTER `contact_desc`,
    ADD COLUMN IF NOT EXISTS `seo_keywords_en` text DEFAULT NULL AFTER `seo_keywords`;

ALTER TABLE `projects`
    ADD COLUMN IF NOT EXISTS `category_en` varchar(50) DEFAULT NULL AFTER `category`,
    ADD COLUMN IF NOT EXISTS `title_en` varchar(255) DEFAULT NULL AFTER `title`,
    ADD COLUMN IF NOT EXISTS `description_en` text DEFAULT NULL AFTER `description`,
    ADD COLUMN IF NOT EXISTS `problem_en` text DEFAULT NULL AFTER `problem`,
    ADD COLUMN IF NOT EXISTS `solution_en` text DEFAULT NULL AFTER `solution`,
    ADD COLUMN IF NOT EXISTS `details_en` longtext DEFAULT NULL AFTER `details`,
    ADD COLUMN IF NOT EXISTS `chart_data_json_en` longtext DEFAULT NULL AFTER `chart_data_json`,
    ADD COLUMN IF NOT EXISTS `result_text_en` varchar(100) DEFAULT NULL AFTER `result_text`,
    ADD COLUMN IF NOT EXISTS `meta_desc_en` varchar(160) DEFAULT NULL AFTER `meta_desc`;

ALTER TABLE `impact_metrics`
    ADD COLUMN IF NOT EXISTS `metric_name_en` varchar(100) DEFAULT NULL AFTER `metric_name`,
    ADD COLUMN IF NOT EXISTS `metric_value_en` varchar(50) DEFAULT NULL AFTER `metric_value`;

ALTER TABLE `hero_chat`
    ADD COLUMN IF NOT EXISTS `question_en` text DEFAULT NULL AFTER `question`,
    ADD COLUMN IF NOT EXISTS `answer_en` text DEFAULT NULL AFTER `answer`;

ALTER TABLE `skills`
    ADD COLUMN IF NOT EXISTS `skill_name_en` varchar(100) DEFAULT NULL AFTER `skill_name`;

ALTER TABLE `experience`
    ADD COLUMN IF NOT EXISTS `role_en` varchar(100) DEFAULT NULL AFTER `role`,
    ADD COLUMN IF NOT EXISTS `year_range_en` varchar(50) DEFAULT NULL AFTER `year_range`,
    ADD COLUMN IF NOT EXISTS `description_en` text DEFAULT NULL AFTER `description`;

ALTER TABLE `education`
    ADD COLUMN IF NOT EXISTS `degree_en` varchar(100) DEFAULT NULL AFTER `degree`,
    ADD COLUMN IF NOT EXISTS `year_range_en` varchar(50) DEFAULT NULL AFTER `year_range`;

ALTER TABLE `articles`
    ADD COLUMN IF NOT EXISTS `title_en` varchar(255) DEFAULT NULL AFTER `title`,
    ADD COLUMN IF NOT EXISTS `content_en` longtext DEFAULT NULL AFTER `content`,
    ADD COLUMN IF NOT EXISTS `meta_desc_en` varchar(160) DEFAULT NULL AFTER `meta_desc`;
