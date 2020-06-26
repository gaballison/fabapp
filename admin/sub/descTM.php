<?php

/*
 * License - FabApp V 0.9
 * 2016-2017 CC BY-NC-AS UTA FabLab
 *
 * Ajax called by training_certificate.php
 */
include_once (filter_input(INPUT_SERVER,'DOCUMENT_ROOT').'/connections/db_connect8.php');
include_once (filter_input(INPUT_SERVER,'DOCUMENT_ROOT').'/class/all_classes.php');

if (!empty(filter_input(INPUT_GET, "tm_id"))) {
    if (TrainingModule::regexTMId(filter_input(INPUT_GET, "tm_id"))) {
        $query = "SELECT * FROM `trainingmodule` WHERE tm_id = $_GET[tm_id] LIMIT 1";
    } else {
        echo ("Error");
        exit();
    }
} else {
    echo ("Error");
    exit();
}

if ($result = $mysqli->query($query)){
    if ($result->num_rows == 0){
        echo ("None");
    } else {
        while($row = $result->fetch_assoc()){
            echo $row['tm_desc'];
        }
    }
} else {
    error_log("admin/sub/descTM.php: SQL error: $mysqli->error");
    echo ("ERROR");
    echo $mysqli->error;
}?>