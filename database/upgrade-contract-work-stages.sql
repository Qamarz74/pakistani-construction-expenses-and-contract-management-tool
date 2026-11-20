-- Adds classification to approved work, without changing agreements, costs or payments.
-- Existing entries remain unassigned until their stage is explicitly selected.
SET @tracker_stage_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='contract_work_costs' AND column_name='work_type_id');
SET @tracker_stage_upgrade = IF(@tracker_stage_exists=0,
 'ALTER TABLE contract_work_costs ADD COLUMN work_type_id INT UNSIGNED NULL AFTER contract_id, ADD CONSTRAINT fk_contract_work_stage FOREIGN KEY (work_type_id) REFERENCES work_types(id)',
 'SELECT 1');
PREPARE tracker_stage_statement FROM @tracker_stage_upgrade;
EXECUTE tracker_stage_statement;
DEALLOCATE PREPARE tracker_stage_statement;
