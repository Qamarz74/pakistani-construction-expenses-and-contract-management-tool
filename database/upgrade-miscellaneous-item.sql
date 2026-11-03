-- Existing installations: separate loading from one-time miscellaneous purchases.
-- Saved expense descriptions, units, rates and totals remain unchanged.
START TRANSACTION;
UPDATE items SET name='Loading / unloading' WHERE name='Loading / Miscellaneous';
INSERT INTO items(name,category_id,unit_id,rate_unit_id,rate_basis)
SELECT 'Miscellaneous / Other',c.id,u.id,u.id,1
FROM categories c CROSS JOIN units u
WHERE c.name='Miscellaneous' AND u.name='Piece'
AND NOT EXISTS (SELECT 1 FROM items WHERE name='Miscellaneous / Other')
ORDER BY c.id,u.id LIMIT 1;
COMMIT;
