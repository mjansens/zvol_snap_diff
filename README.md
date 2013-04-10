zvol_snap_diff
==============

diff for zfs volumes snapshots

Contains 2 PHP programs:
zvol_snap_diff.php diffs 2 zfs volumes snapshots and outputs a diff stream
cow_patch.php applies the diff to a block device (same blocksize as source)
