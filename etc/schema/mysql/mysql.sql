CREATE TABLE discourse_notifier_user (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  email VARCHAR(254) NOT NULL UNIQUE KEY,
  last_email BIGINT
);

CREATE INDEX discourse_notifier_ix_user_last_email ON discourse_notifier_user(last_email);

CREATE TABLE discourse_notifier_category (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL UNIQUE KEY,
  ctime BIGINT NOT NULL
);

CREATE INDEX discourse_notifier_ix_category_ctime ON discourse_notifier_category(ctime);

CREATE TABLE discourse_notifier_tag (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL UNIQUE KEY,
  ctime BIGINT NOT NULL
);

CREATE INDEX discourse_notifier_ix_tag_ctime ON discourse_notifier_tag(ctime);
