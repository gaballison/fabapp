<?php
/*
 *   CC BY-NC-AS UTA FabLab 2016-2018
 *   FabApp V 0.91
 * 
 */
 
include_once ($_SERVER ['DOCUMENT_ROOT'] . '/connections/db_connect8.php');
include_once ($_SERVER ['DOCUMENT_ROOT'] . '/class/all_classes.php');


if ( !empty($_GET["val"]) && (is_numeric($_GET["val"]) || is_int($_GET["val"]))){
    $r_id = filter_input(INPUT_GET, "val");
    echo "<option disabled>Select Operator</option>";
    if ($result = $mysqli->query(
        "SELECT DISTINCT `users`.`operator` FROM `users`
        WHERE `users`.`r_id` = $r_id ORDER BY `operator`;"
    )){
        while($row = $result->fetch_assoc()){
            echo "<option value=$row[operator]>$row[operator]</option>";
        }
    }
    else
    {
        echo "<option value='' selected disabled hidden>SQL error: $mysqli->error</option>";
        error_log("admin/sub/ob_getOperators.php: SQL error: $mysqli->error");
    }
} else {
    echo "<option selected disabled hidden>Invalid Role</option>";
}

?>