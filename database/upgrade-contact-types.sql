-- Existing installations: run once before using the expanded contact types.
-- Existing contacts and their type values are preserved.
ALTER TABLE contacts MODIFY COLUMN type ENUM('Supplier','Contractor','Both','Labourer','Mason','Electrician','Plumber','Carpenter','Painter','Transporter','Other') NOT NULL DEFAULT 'Supplier';
