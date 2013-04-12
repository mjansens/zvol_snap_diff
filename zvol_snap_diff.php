<?php
/*
 *  zvol_snap_diff.php
 *
 * Author: Michel Jansens
 * Date April 08, 2013.
 * Version: 0.1
 * Dependencies: php5.x, zdb, zfs
 * 
 */

/*
 * This programs compares 2 snapshots and generates a diff stream (usable by cowpatch.php)
 * diff stream format:
 * <blocksize (hexadecimal)>\n
 * '+'|'-'|'M' <offset (hexadecimal)>\n
 * [<block data>]
 * ....
 * 
 * <block data> is included only if '-d' option is used and mod char is '+' or 'M'
 * + is a new block
 * M block exists in both snaps but checksum differ
 * - is a block that has been trimmed/unmapped (cow_patch.php does nothing at this time). There is no block data following a - 
 * 
 * example:
 * host1> php zvol_snap_diff.php -d zones/imageuuid@dataset zones/vmuuid-disk0@migration | ssh host2 "php cow_patch.php /dev/zvol/dsk/vmuuid-disk0"
 */
if ($argc < 4 || $argc > 5) {
    fprintf(STDERR, "usage: %s [-d] <zvol_snap1> <zvol_snap2> <blocksize(decimal)>\n", $argv[0]);
    fprintf(STDERR, "      computes the differences between 2 snapshots of ZFS volumes(zvols) and returns list of differing blocks in stdout. If -d is used, (binary) data of blocks is included\n");
    fprintf(STDERR, "example: %s zones/image@dataset zones/vm@snap 8192\n", $argv[0]);
    fprintf(STDERR, "This will generate a stream of differing block between image@dataset and vm@snap\n");
    exit(1);
}
$arg1 = 1;
if ($argv[1] == "-d") {
    $send_data = true;
    $arg1+=1;
} else {
    $send_data = false;
}
//blocksize of zvols (could get it from zdb)
$blocksize = $argv[$arg1 + 2];

$start_time=time();

//run zdb for both snapshots
$fp1 = popen("zdb -vvvvv " . $argv[$arg1], "r");
if (!$fp1) {
    fprintf(STDERR, "error launching comand \"zdb -vvvvv %s\"\n", $argv[$arg1]);
    exit(1);
}
$fp2 = popen("zdb -vvvvv " . $argv[$arg1 + 1], "r");
if (!$fp2) {
    fprintf(STDERR, "error launching comand \"zdb -vvvvv %s\"\n", $argv[$arg1 + 1]);
    exit(1);
}

if ($send_data) {
//we want to send the actual data of blocks, so we need to read them
//$zvolfp1=fopen("/dev/zvol/dsk/".$argv[$arg1],"r");
    $zvolfp2 = fopen("/dev/zvol/dsk/" . $argv[$arg1 + 1], "r");
}

//get first element from both sources
//get first block info of first snap
list($res1, $offset1, $chksum1) = get_next_blockinfo(true, $fp1);
if (!$res1) {
    fprintf(STDERR, "error finding zvol data in zdb output file %s\n", $argv[$arg1]);
    exit(1);
}
//get first block info of last snap
list($res2, $offset2, $chksum2) = get_next_blockinfo(true, $fp2);
if (!$res2) {
    fprintf(STDERR, "error finding zvol data in zdb output file %s\n", $argv[$arg1 + 1]);
    exit(1);
}
//blocksize is sent in decimal
printf("%x\n", $blocksize);
$blocks_added = 0;
$blocks_removed = 0;
$blocks_modified = 0;
//now start comparing both block allocation and checksums
// Both sources have to be sorted given main key (offset). This seems to be the case with zdb
while ($res1 && $res2) {

    if ($offset1 == $offset2) { //both sources have this block
        if ($chksum1 != $chksum2) {
            //but blocks are different
            printf("M %x\n", $offset2);
            if ($send_data) {
                //get block data and send it
                fseek($zvolfp2, $offset2);
                $buff = fread($zvolfp2, $blocksize);
                fwrite(STDOUT, $buff, $blocksize);
            }
            $blocks_modified+=1;
        }
        //get next values
        $offset1_old = $offset1;
        $offset2_old = $offset2;
        list($res1, $offset1, $chksum1) = get_next_blockinfo(false, $fp1);
        list($res2, $offset2, $chksum2) = get_next_blockinfo(false, $fp2);
        if ($res1 && $offset1_old >= $offset1) {
            fprintf(STDERR, "error offsets are not ordered in %s\n", $argv[$arg1]);
            exit(1);
        }
        if ($res2 && $offset2_old >= $offset2) {
            fprintf(STDERR, "error offsets are not ordered in %s\n", $argv[$arg1 + 1]);
            exit(1);
        }
    } else {
        //offset are not equal -> some block is missing somewhere
        if ($offset1 > $offset2) {
            //a new block was created since snap1
            printf("+ %x\n", $offset2);
            if ($send_data) {
                //get block data and send it
                fseek($zvolfp2, $offset2);
                $buff = fread($zvolfp2, $blocksize);
                fwrite(STDOUT, $buff, $blocksize);
            }
            $blocks_added+=1;
            $offset2_old = $offset2;
            list($res2, $offset2, $chksum2) = get_next_blockinfo(false, $fp2);
            if ($res2 && $offset2_old >= $offset2) {
                fprintf(STDERR, "error offsets are not ordered in %s\n", $argv[$arg1 + 1]);
                exit(1);
            }
        } else {
            //a block has been deleted since snap1
            printf("- %x\n", $offset1);
            $blocks_removed+=1;
            $offset1_old = $offset1;
            list($res1, $offset1, $chksum1) = get_next_blockinfo(false, $fp1);
            if ($res1 && $offset1_old >= $offset1) {
                fprintf(STDERR, "error offsets are not ordered in %s\n", $argv[$arg1]);
                exit(1);
            }// if not sorted
        }//else offset1>
    }//end else offset1=
}//end while
//now we handle remaining blocks
//blocks in excess in snap1 (that where deleted in snap2)
while ($res1) {
    printf("- %x\n", $offset1);
    $blocks_removed+=1;
    $offset1_old = $offset1;
    list($res1, $offset1, $chksum1) = get_next_blockinfo(false, $fp1);
    if ($res1 && $offset1_old >= $offset1) {
        fprintf(STDERR, "error offsets are not ordered in %s\n", $argv[$arg1]);
        exit(1);
    }
}

//blocks in excess in snap2 (that where created since snap1)
while ($res2) {
    printf("+ %x\n", $offset2);
    if ($send_data) {
        //get block data and send it
        fseek($zvolfp2, $offset2);
        $buff = fread($zvolfp2, $blocksize);
        fwrite(STDOUT, $buff, $blocksize);
    }
    $blocks_added+=1;
    $offset2_old = $offset2;
    list($res2, $offset2, $chksum2) = get_next_blockinfo(false, $fp2);
    if ($res2 && $offset2_old >= $offset2) {
        fprintf(STDERR, "error offsets are not ordered in %s\n", $argv[$arg1 + 1]);
        exit(1);
    }
}

$spent_time=time()-$start_time;   
fprintf(STDERR, "Block diff check completed: %d added %d removed %d modified in %d seconds\n", $blocks_added, $blocks_removed, $blocks_modified,$spent_time);
exit(0);
/*-----------------------------------------------------------------------------------------*/


function get_next_blockinfo($firstcall, $fp) {
    if ($firstcall) {
        //first tile called so fp is at begining of file. We need to browse to zvol blocks (look for a "zvol object" section in file)
        $found1 = false;
        $found2 = false;
        $found = false;
        do {
            $line = fgets($fp);
            $found1 = strstr($line, "Object  lvl   iblk   dblk  dsize  lsize   %full  type");
            if ($found1) {
                $line = fgets($fp);
                $found2 = strstr($line, " zvol object ");
                if ($found2)
                    $found = true;
            }
        } while (!$found && !feof($fp));

        if (feof($fp))
            return array(false, false, false); //did not find
    }
    //now we found the begining or with subsequent calls, lets find next  "L0" line (a line with L0 in second position space delimited)
    $found = false;
    do {
        $line = fgets($fp);
        $res = sscanf($line, "%s %s", $offset_str, $level);
        if ($level == "L0")
            $found = true;
    } while (!$found && !feof($fp) && trim($line) != "");

    if (feof($fp))
        return array(false, false, false); //could not find a 'L0' block
    if (trim($line) == "")
        return array(false, false, false); //empty line means the end of zvol section
        
//     //we did find a L0 block so now decode offset and get checksum
    $res = sscanf($offset_str, "%x", $offset);
    if ($res != 1)
        return array(false, false, false);
    //look for checksum
    $cksum_pos = strpos($line, " cksum=");
    if ($cksum_pos === false)
        return array(false, false, false);
    $cksum_str = trim(substr($line, $cksum_pos + 7));
    return array(true, $offset, $cksum_str);
}

?>
