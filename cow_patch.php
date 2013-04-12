<?php
/*
 *  cow_patch.php
 *
 * Author: Michel Jansens
 * Date April 08, 2013.
 * Version: 0.1
 * Dependencies: php5.x
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
 * + is a new block
 * M block exists in both snaps but checksum differ
 * - is a block that has been trimmed/unmapped (cow_patch.php does nothing at this time). There is no block data following a - 
 * 
 * example:
 * host1> php zvol_snap_diff.php -d zones/imageuuid@dataset zones/vmuuid-disk0@migration | ssh host2 "php cow_patch.php /dev/zvol/dsk/vmuuid-disk0"
 */
$start_time=time();

 if($argc!=2){
     fprintf(STDERR,"syntax: %s <block_device_path>\nReads a block diff from standard input and (re)write created/modified blocks.\n",$argv[0]);
     exit(1);
 }
//open device for read and write
$file_fp=fopen($argv[1],"r+");
if(!$file_fp){
  fprintf(STDERR,"could not open %s\n",$argv[1]);
  exit(2);
}

//get blocksize
$readbuf=fgets(STDIN);
sscanf($readbuf, "%x", $blocksize);	
fprintf(STDERR,"blocksize: %010d\n",$blocksize);

//create a block full of zeros, for trim/unmap disapearing blocks
$zeroblock=create_zero_block($blocksize);

$blocks_added = 0;
$blocks_removed = 0;
$blocks_modified = 0;

//read next mod line (type offset)
$line=fgets(STDIN);
	while( !feof(STDIN)){
		sscanf($line, "%c %x",$mod,$offset);
		//now read the block's data
                if($mod=='+' || $mod=='M'){
                    $block_read_size=fread_block(STDIN, $blocksize, $buffer);
		    //?complete blocks
		    if($block_read_size!=$blocksize){
		        fprintf(STDERR,"could not read a complete block from stdin only got %d\n",$block_read_size);
		        exit(3);
		    }
		    //position cursor to the righ block
		    fseek($file_fp, $offset, SEEK_SET );	
		    //do the writing (on a new block as per Copy on Write)
                   $write_len=fwrite($file_fp, $buffer, $blocksize);
		   //fprintf(STDERR,"Would write block hex %x\n",$offset);  
                   if($write_len!=$blocksize){
		       fprintf(STDERR,"error writing at offset %d\n",$offset);
		       exit(4);
		   }
                   //stats
                   if($mod=='+')
                       $blocks_added+=1;
                   else
                       $blocks_modified+=1;
                   
                 }//end if $mod==+|M
                 else{
                    if($mod=='-'){
                        //block suppression should trim or write zeros
                        //lets write zeros, if compression is enabled on volume  this will destroy block (in ZFS at least)
                        // without compression enabled it is just a block full of zeros, not a big deal
                        fseek($file_fp, $offset, SEEK_SET );
                        $write_len=fwrite($file_fp, $buffer, $blocksize);
		        //fprintf(STDERR,"Would write zero block hex %x\n",$offset);  
                        if($write_len!=$blocksize){
		           fprintf(STDERR,"error writing at offset %d\n",$offset);
		           exit(4);
		        }
                        $blocks_removed+=1;
                    }//end if $mod==-   
                 }//end else $mod=+|M
		 //read next mod line
		 $line=fgets(STDIN);
	}//end while
    $spent_time=time()-$start_time;   
    fprintf(STDERR, "Block diff patch completed: %d added %d removed %d modified in %d seconds\n", $blocks_added, $blocks_removed, $blocks_modified,$spent_time);
        
    /**
     *
     * @param type $blocksize
     * @return binary_string full of zeros 
     */
    function  create_zero_block($blocksize){
      $fp=  fopen("/dev/null", 'r');
      if(!$fp){
        fprintf(STDERR,"error opening /dev/null");
        exit(2);
      }
      $zero_block="";
      for($i=0;$i<$blocksize;$i++)$zero_block.="\0";
      return($zero_block);
    }
    
    /**
     * Reads $size bytes from filedescriptor and make sure we really read all bytes or eof is reached
     * @param type $fp
     * @param type $size
     * @param type $buffer
     * 
     */
  function fread_block($fp,$size,&$buffer){
      $buffer='';
      $read_size=0;
     //PHP does non blocking read on stdin it seems so it doesn't alway return expected number of bytes
      while($read_size != $size && !feof($fp)){
          $buffer.=fread($fp,$size-$read_size);
          $read_size=strlen($buffer);
      }
      return $read_size;
      
  }
?>
