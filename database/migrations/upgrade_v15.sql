ALTER TABLE doctors_masterlist ADD INDEX idx_doctors_name_active (active, dr_name(100));
ALTER TABLE hospitals_master ADD INDEX idx_hospitals_name_active (active, name(100));
ALTER TABLE medicines_master ADD INDEX idx_medicines_name_active (active, name(100));
