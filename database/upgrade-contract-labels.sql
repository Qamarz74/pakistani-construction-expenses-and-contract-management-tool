-- Rename shipped defaults only; historical purchase snapshots remain unchanged.
UPDATE units SET name='Contract' WHERE name='Theka';
UPDATE categories SET name='Labour / Contract' WHERE name='Labour / Theka';
UPDATE items SET name='Labour / Contract' WHERE name='Labour / Theka';
