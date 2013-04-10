zvol_snap_diff
==============

"Optimized" diff for zfs volumes (zvol) snapshots
I wrote this after  trying for days to "zfs send|zfs receive"  SmartOS VM disks to another host while keeping the "clone architecture" intact.

2 PHP programs:
zvol_snap_diff.php : diffs 2 zfs volumes snapshots and outputs a diff stream.
cow_patch.php : applies the diff to a block device (same blocksize as source)

zvol_snap_diff.php uses 'zdb -vvvvv <snapshot>' output to get a list of blocks (offset+checksums) in both snaps and compares checksums of blocks.

