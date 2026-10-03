-- This upgrade is not reverted: shrinking the columns would truncate the data stored since,
-- and the wider columns work with older versions of the code.
DO 0;
