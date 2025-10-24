-- Sample data for local development
INSERT INTO bus_company (id, name, logo_path) VALUES
  ('cmp-1', 'Kardemir Turizm', '/assets/kardemir.png'),
  ('cmp-2', 'Safran Ekspres', '/assets/safran.png'),
  ('cmp-3', 'Anatolia Lines', '/assets/anatolia.png'),
  ('cmp-4', 'Ege Express', '/assets/ege.png'),
  ('cmp-5', 'Mersin Seyahat', '/assets/mersin.png');

INSERT INTO user (id, full_name, email, password_hash, role, company_id, balance_kurus) VALUES
  ('usr-1', 'Deneme Yolcu', 'yolcu@example.com', '$2y$10$LohrOEeUoMJYJSLPeOUjG.Q6DJTWSpvFbbpCeRL8wKlrOA1gM2Qli', 'USER', NULL, 30000),
  ('usr-2', 'Selin Kalkan', 'selin@example.com', '$2y$10$VXYLDCXwNfVMA5ARPEQeAer/zy3VACyRc/TkhvaE8QXcmPU75cvHW', 'USER', NULL, 45000),
  ('usr-3', 'Murat Demir', 'murat@example.com', '$2y$10$Qx6.O6rSEjywLaVaHgPwE.La5oefXp4GfeHLLbrvPzpGVLWELuXda', 'USER', NULL, 120000),
  ('usr-4', 'Firma Yetkilisi', 'yetkili@example.com', '$2y$10$3USpNrXfStaIHRyt55gEf.9oA7I17VkTaCGHg6mhGEOHQsvEWMnGK', 'FIRMA_ADMIN', 'cmp-1', 100000),
  ('usr-5', 'Ayse Aksu', 'ayse@anatolia.com', '$2y$10$FRQvi4rUt7wLMR.jYjNnqeANyNpqjzsboCctFnX9doWorMPILGs4q', 'FIRMA_ADMIN', 'cmp-3', 150000),
  ('usr-6', 'Kemal Oz', 'kemal@egeexpress.com', '$2y$10$AY7kuQN4ixyPU/g7.IZu2eYPFGH.Lj.gONWS8b6N8UDesm..rdoGS', 'FIRMA_ADMIN', 'cmp-4', 90000),
  ('usr-7', 'Sistem Yonetici', 'admin@example.com', '$2y$10$9jJz0Q6Dhxqm6BN0fNxmG.5BRqvBk3OqqKW9WDvt.jof4qt5ia', 'ADMIN', NULL, 0);

INSERT INTO coupons (id, code, rate_or_kurus, kind, usage_limit, start_time, end_time, company_id) VALUES
  ('c-1', 'EKIM20', 20, 'YUZDE', 100, datetime('now','-1 day'), datetime('now','+30 day'), NULL),
  ('c-2', 'FLAT1500', 1500, 'SABIT', NULL, datetime('now','-1 day'), datetime('now','+60 day'), 'cmp-1'),
  ('c-3', 'ANATOLIA15', 15, 'YUZDE', 200, datetime('now','-2 day'), datetime('now','+90 day'), 'cmp-3'),
  ('c-4', 'EGE50', 5000, 'SABIT', 50, datetime('now','-7 day'), datetime('now','+120 day'), 'cmp-4'),
  ('c-5', 'MERSIN10', 10, 'YUZDE', 150, datetime('now','-1 day'), datetime('now','+60 day'), 'cmp-5');

INSERT INTO trips (id, company_id, origin_city, destination_city, departure_time, arrival_time, price_kurus, capacity) VALUES
  ('trp-1', 'cmp-1', 'Karabuk', 'Ankara', datetime('now','+1 day','10:00'), datetime('now','+1 day','13:00'), 15000, 40),
  ('trp-2', 'cmp-2', 'Karabuk', 'Istanbul', datetime('now','+2 day','09:00'), datetime('now','+2 day','16:00'), 45000, 40),
  ('trp-3', 'cmp-3', 'Ankara', 'Izmir', datetime('now','+3 day','08:00'), datetime('now','+3 day','15:30'), 55000, 45),
  ('trp-4', 'cmp-3', 'Izmir', 'Ankara', datetime('now','+4 day','12:00'), datetime('now','+4 day','19:00'), 55000, 45),
  ('trp-5', 'cmp-4', 'Istanbul', 'Antalya', datetime('now','+1 day','07:30'), datetime('now','+1 day','14:00'), 62000, 42),
  ('trp-6', 'cmp-4', 'Antalya', 'Istanbul', datetime('now','+5 day','15:30'), datetime('now','+5 day','22:30'), 62000, 42),
  ('trp-7', 'cmp-1', 'Ankara', 'Trabzon', datetime('now','+6 day','06:30'), datetime('now','+6 day','14:45'), 70000, 50),
  ('trp-8', 'cmp-5', 'Mersin', 'Izmir', datetime('now','+3 day','08:30'), datetime('now','+3 day','16:45'), 52000, 44);

INSERT INTO tickets (id, trip_id, user_id, seat_number, status, price_paid_kurus, coupon_code, pdf_path) VALUES
  ('tkt-1', 'trp-1', 'usr-1', 5, 'ALINDI', 13500, 'EKIM20', 'biletler/tkt-1.pdf'),
  ('tkt-2', 'trp-2', 'usr-2', 12, 'ALINDI', 45000, NULL, 'biletler/tkt-2.pdf'),
  ('tkt-3', 'trp-5', 'usr-3', 8, 'IPTAL', 62000, NULL, 'biletler/tkt-3.pdf');

INSERT INTO booked_seats (id, trip_id, seat_number) VALUES
  ('bks-1', 'trp-1', 5),
  ('bks-2', 'trp-2', 12);
