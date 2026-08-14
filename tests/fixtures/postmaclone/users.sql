-- sample dump
CREATE TABLE users (
  id integer PRIMARY KEY,
  email text,
  first_name text,
  last_name text,
  status text
);

INSERT INTO "users" ("id", "email", "first_name", "last_name", "status") VALUES
(1, 'alice@example.com', 'Alice', 'Smith', 'active'),
(2, 'bob@example.com', 'Bob', 'Jones', 'active'),
(3, NULL, 'Carol', 'Null', 'inactive');
