<?php
use PDO;
use PHPMailer\PHPMailer\PHPMailer;

require __DIR__ . "/vendor/autoload.php";

$CfgFile = "backup.config";

$Cfg = parse_ini_file( $CfgFile );


//Edit below values with your own values.
$db_server 		                = $Cfg["DB_SERVER"];
$db_name 		                = $Cfg["DB_NAME"];
$db_user 		                = $Cfg["DB_USER"];
$db_pass 		                = $Cfg["DB_PASS"];

$site_url		                = $Cfg["SITE_URL"];
$email_host                     = $Cfg["EMAIL_HOST"];
$smtp_username                  = $Cfg["SMTP_USERNAME"];
$smtp_pass                      = $Cfg["SMTP_PASS"];
$from_email 	                = $Cfg["FROM_EMAIL"];
$from_name 		                = $Cfg["FROM_NAME"];
$mail_to1  		                = $Cfg["MAIL_TO1"];
$mail_to1_name                  = $Cfg["MAIL_TO1_NAME"];
$mail_to2                       = $Cfg["MAIL_TO2"];
$mail_to2_name                  = $Cfg["MAIL_TO2_NAME"];

$save_dir		                = $Cfg["SAVE_DIR"];
$file_name_prefix	            = $Cfg["FILE_NAME_PREFIX"];
$time_zone          	        = $Cfg["TIME_ZONE"];
$compression        	        = $Cfg["COMPRESSION"];
$delete_bkupfile_after_email  	= $Cfg["DELETE_BKUPFILE_AFTER_EMAIL"];

$date 				            = date( $Cfg["DATE_FORMAT"] );
/*
---- Do NOT EDIT BELOW -----
*/
$backup_config = array(
    'DB_HOST' => $db_server,
    'DB_NAME' => $db_name,
    'DB_USERNAME' => $db_user,
    'DB_PASSWORD' => $db_pass,
    'INCLUDE_DROP_TABLE' => false,
    'SAVE_DIR' => $save_dir	,
    'SAVE_AS' => $file_name_prefix,
    'APPEND_DATE_FORMAT' => 'Y_m_d_H_i_s',
    'TIMEZONE' => ''.$time_zone.'',
    'COMPRESS' => $compression,
);

$backup_db =  backupDB($backup_config);

if($backup_db) {

    $files = array_merge(glob("./*.sql.gz"), glob("./*.sql"));
    $files = array_combine($files, array_map("filemtime", $files));
    arsort($files);
    $newest_file = key($files);

    $mail = new PHPMailer();
    $mail->IsHTML(true); 
    $mail->Host = $email_host; // SMTP server
    $mail->AddAddress($mail_to1, $mail_to1_name);
    if(!empty($mail_to2)){
        $mail->AddAddress($mail_to2, $mail_to2_name);
    }			
    $mail->IsSMTP();
    $mail->SMTPAuth = true;
    $mail->SMTPSecure = 'tls'; // tls or ssl
    $mail->Mailer = "smtp";
    $mail->SMTPDebug = false;
    $mail->Username = $smtp_username;
    $mail->Password = $smtp_pass;
    $mail->Port = 587;  // set the SMTP port , 587 if tls used, 465 if ssl used.
    $mail->AddReplyTo($from_email, $from_name);
    $mail->AddCustomHeader( "X-Confirm-Reading-To:".$from_email."" );
    $mail->AddCustomHeader( "Return-Receipt-To:".$from_email."" );
    $mail->ConfirmReadingTo = $from_email;
    $mail->From = $from_email; // Your Full Email ID on your Domain
    $mail->FromName = $from_name; // Your name or Domain
    $mail->WordWrap = 50; 
    $mail->Subject = '['.$from_name.'] Cron Backup MySQL On - ' . $date;
    $mail->Body    = $backup_db.' File is attached via cron';
        if (!$mail->AddAttachment($newest_file)) {   
            echo 'Error : ' . $mail->ErrorInfo . "\n";
            $mail->Body .= "\n" . 'Error : ' . $mail->ErrorInfo;
        }
        if (!$mail->Send()){
            echo 'Message could not be sent. <p>';
            echo 'Mailer Error: ' . $mail->ErrorInfo;
            exit;
        }
        echo 'Message has been sent';
        if($delete_bkupfile_after_email=='Yes'){
        unlink($newest_file); 
    }		
}

function backupDB(array $config): string
{
    $db = new PDO("mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']}; charset=utf8", $config['DB_USERNAME'], $config['DB_PASSWORD']);
    $db->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_NATURAL);

    date_default_timezone_set($config['TIMEZONE']);
    $do_compress = $config['COMPRESS'];

    if ($do_compress) {
        $save_string = $config['SAVE_DIR'] . $config['SAVE_AS'] . date($config['APPEND_DATE_FORMAT']) . '.sql.gz';
        $zp = gzopen($save_string, "a9");
    } else {
        $save_string = $config['SAVE_DIR'] . $config['SAVE_AS'] . date($config['APPEND_DATE_FORMAT']) . '.sql';
        $handle = fopen($save_string, 'a+');
    }

    //array of all database field types which just take numbers
    $numtypes = array('tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'float', 'double', 'decimal', 'real');

    $return = "";
    $return .= "CREATE DATABASE `{$config['DB_NAME']}`;\n";
    $return .= "USE `{$config['DB_NAME']}`;\n";

    //get all tables
    $pstm1 = $db->query('SHOW TABLES');
    while ($row = $pstm1->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    //cycle through the table(s)
    foreach ($tables as $table) {
        $result = $db->query("SELECT * FROM $table");
        $num_fields = $result->columnCount();
        $num_rows = $result->rowCount();

        if ($config['INCLUDE_DROP_TABLE']) {
            $return .= 'DROP TABLE IF EXISTS `' . $table . '`;';
        }

        //table structure
        $pstm2 = $db->query("SHOW CREATE TABLE $table");
        $row2 = $pstm2->fetch(PDO::FETCH_NUM);
        $ifnotexists = str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $row2[1]);
        $return .= "\n\n" . $ifnotexists . ";\n\n";

        if ($do_compress) {
            gzwrite($zp, $return);
        } else {
            fwrite($handle, $return);
        }
        $return = "";

        //insert values
        if ($num_rows) {
            $return = 'INSERT INTO `' . $table . '` (';
            $pstm3 = $db->query("SHOW COLUMNS FROM $table");
            $count = 0;
            $type = array();

            while ($rows = $pstm3->fetch(PDO::FETCH_NUM)) {
                if (stripos($rows[1], '(')) {
                    $type[$table][] = stristr($rows[1], '(', true);
                } else {
                    $type[$table][] = $rows[1];
                }

                $return .= "`" . $rows[0] . "`";
                $count++;
                if ($count < ($pstm3->rowCount())) {
                    $return .= ", ";
                }
            }

            $return .= ")" . ' VALUES';

            if ($do_compress) {
                gzwrite($zp, $return);
            } else {
                fwrite($handle, $return);
            }
            $return = "";
        }
        $counter = 0;
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $return = "\n\t(";

            for ($j = 0; $j < $num_fields; $j++) {

                if (isset($row[$j])) {

                    //if number, take away "". else leave as string
                    if ((in_array($type[$table][$j], $numtypes)) && (!empty($row[$j]))) {
                        $return .= $row[$j];
                    } else {
                        $return .= $db->quote($row[$j]);
                    }
                } else {
                    $return .= 'NULL';
                }
                if ($j < ($num_fields - 1)) {
                    $return .= ',';
                }
            }
            $counter++;
            if ($counter < ($result->rowCount())) {
                $return .= "),";
            } else {
                $return .= ");";
            }
            if ($do_compress) {
                gzwrite($zp, $return);
            } else {
                fwrite($handle, $return);
            }
            $return = "";
        }
        $return = "\n\n-- ------------------------------------------------ \n\n";
        if ($do_compress) {
            gzwrite($zp, $return);
        } else {
            fwrite($handle, $return);
        }
        $return = "";
   
	
	}

    $error1 = $pstm2->errorInfo();
    $error2 = $pstm3->errorInfo();
    $error3 = $result->errorInfo();
    echo $error1[2];
    echo $error2[2];
    echo $error3[2];

    if ($do_compress) {
        gzclose($zp);
    } else {
        fclose($handle);
    }
   
	return "{$config['DB_NAME']} saved as $save_string";
}

