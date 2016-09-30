<?php
require_once "simple_html_dom.php";

/*
 *
 */
function generateRandomString($length = 10)
{
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

/*
 *
 */
function saveDeveloper($mysqli, $name, $enabled)
{
    if ($stmt = $mysqli->prepare("INSERT INTO developers (name,enabled) VALUES (?, ?)")) {
        $stmt->bind_param('si', $name, $enabled);
        $stmt->execute();
    } else {
        var_dump($mysqli->error);
    }
    return $mysqli->insert_id;
}

/*
 *
 */
function saveComplex($mysqli, $developer_id, $name, $address, $enabled)
{
    if ($stmt = $mysqli->prepare("INSERT INTO complex (developer_id,name,address,enabled) VALUES (?, ?, ?, ?)")) {
        $stmt->bind_param('issi', $developer_id, $name, $address, $enabled);
        $stmt->execute();
    } else {
        var_dump($mysqli->error);
    }
    return $mysqli->insert_id;
}

/*
 *
 */
function isExistImage($mysqli, $complex_id, $name)
{
    if ($stmt = $mysqli->prepare("select count(*) from complex_images where $complex_id = ? and filename = ?")) {
        $stmt->bind_param('is', $complex_id, $name);
        $stmt->execute();

        $count = 0;
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        return $count;
    } else {
        var_dump($mysqli->error);
    }
}

/*
 *
 */
function saveImage($mysqli, $comlex_id, $name)
{
    if ($stmt = $mysqli->prepare("INSERT INTO complex_images (complex_id,filename) VALUES (?, ?)")) {
        $stmt->bind_param('is', $comlex_id, $name);
        $stmt->execute();
    } else {
        var_dump($mysqli->error);
    }
    return $mysqli->insert_id;
}

/*
 *
 */
function createDir($name)
{
    if (file_exists($name) == FALSE) {
        mkdir($name, 0777, true);
    }
}

/*
 *
 */
function GetDom($url)
{
    set_error_handler(
        create_function(
            '$severity, $message, $file, $line',
            'throw new ErrorException($message, $severity, $severity, $file, $line);'
        )
    );
    try {
        $context = stream_context_create(array('http' => array('timeout' => 20)));  //5 seconds timeout
        if ($html = file_get_contents($url, 0, $context))
            return str_get_html($html);

    } catch (Exception $e) {
        echo $e->getMessage();
    }

    restore_error_handler();
}

/*
 *
 */
function GetImg($url)
{
    set_error_handler(
        create_function(
            '$severity, $message, $file, $line',
            'throw new ErrorException($message, $severity, $severity, $file, $line);'
        )
    );
    try {
        if ($html = file_get_contents($url))
            return $html;

    } catch (Exception $e) {
        echo $e->getMessage();
    }

    restore_error_handler();
}

$mysqli = mysqli_connect(
    '127.0.0.1',  /* Хост, к которому мы подключаемся */
    'root',       /* Имя пользователя */
    '123zxc',   /* Используемый пароль */
    'kluchi');     /* База данных для запросов по умолчанию */

if (!$mysqli) {
    printf("Невозможно подключиться к базе данных. Код ошибки: %s\n", mysqli_connect_error());
    exit;
}

if (!$mysqli->set_charset("utf8")) {
    printf("Ошибка при загрузке набора символов utf8: %s\n", $mysqli->error);
} else {
    printf("Текущий набор символов: %s\n", $mysqli->character_set_name());
}

function GetDeveloperComplex($d_id, $d_page, $d_path, $mysqli, $developer_id_mysql)
{
    $page = '';
    if ((isset($d_page)) && ($d_page > 0)) {
        $page = '&page=' . $d_page;
    }
    $url = 'http://krisha.kz/complex/search?das[build.comppany]=' . $d_id . '&das[cityId]=all' . $page;
    //echo "Get Complex $url\n";
    $d_html = GetDom($url);
    while ($d_html == false) {
        $d_html = GetDom($url);
    }

    //print_r( $developer_html->find('div[class=realty] table'));
    foreach ($d_html->find('div[class=realty] table tr td') as $complex) {
        $name = $complex->find("p");
        if (isset($name[0])) {
            $name = $name[0]->plaintext;
            $address = $complex->find("span");
            if (count($address) > 0) {     //Check address string
                $address = $address[0]->plaintext;
            } else {
                $address = '';
            }
            $link = $complex->find('a')[0]->href;

            echo "      Complex:" . $name . "\n";
            set_error_handler(create_function(
                '$severity, $message, $file, $line',
                'throw new ErrorException($message, $severity, $severity, $file, $line);'
            ));

            try {
                $complex_path = $d_path . "/" . iconv("UTF-8", "Windows-1251", $name);
            } catch (Exception $e) {
                $complex_path = urldecode($name);
            }
            restore_error_handler();

            createDir($complex_path);

            $complex_mysql_id = saveComplex($mysqli, $developer_id_mysql, $name, $address, 1);
            /*$img = $complex->find('img');
            if(count($img) > 0) {   //check thumbinal image exists
                $img = $img[0]->src;
                $img_thumbinal = $complex_path . "/" . iconv("UTF-8", "Windows-1251", $name) . ".jpg";
                if ($img) {
                    file_put_contents($img_thumbinal, file_get_contents($img));
                }
            }*/

            GetComplexPhotos($link, $complex_path, $mysqli, $complex_mysql_id);
        }
    }
}

function GetComplexPhotos($link, $path, $mysqli, $complexID)
{
    $url = 'http://krisha.kz/' . $link;
    $d_html = GetDom($url);
    while ($d_html == false) {
        $d_html = GetDom($url);
    }

    foreach ($d_html->find('div[class=aPhotos] a') as $img) {
        if ($img->href) {
            $fname = basename(parse_url($img->href, PHP_URL_PATH));
            $imgPath = $path . "/" . $fname;

            $img_data = GetImg($img->href);
            while ($img_data == false) {
                $img_data = GetImg($img->href);
            }

            if(isExistImage($mysqli,$complexID,$fname) ==0) {
                file_put_contents($imgPath, $img_data);

                $newPath = '/var/www/html/kluchi/backend/web/media/uploads/' . $fname;
                echo "          Move:$imgPath to $newPath\n";
                if (copy($imgPath, $newPath) === TRUE) {
                    saveImage($mysqli, $complexID, $fname);
                }
            }
        }
    }

    foreach ($d_html->find('div[class=additional-photos] a') as $img) {
        if ($img->href) {
            $fname = basename(parse_url($img->href, PHP_URL_PATH));
            $imgPath = $path . "/" . $fname;

            $img_data = GetImg($img->href);
            while ($img_data == false) {
                $img_data = GetImg($img->href);
            }

            if(isExistImage($mysqli,$complexID,$fname) ==0) {
                file_put_contents($imgPath, $img_data);

                $newPath = '/var/www/html/kluchi/backend/web/media/uploads/' . $fname;
                echo "          Move:$imgPath to $newPath\n";
                if (copy($imgPath, $newPath) === TRUE) {
                    saveImage($mysqli, $complexID, $fname);
                }
            }
        }
    }
}


$html = file_get_html('http://krisha.kz/complex/');
$save_dir = "/opt/krysha";
$cont = true;

// Find all developers
foreach ($html->find('select') as $element) {
    if ($element->id == 'build_comppany') {
        foreach ($element->find('option') as $opt) {
            $developer_id = $opt->value;

            if ($developer_id == '132') {
                $cont = true;
            }
            if ((is_numeric($developer_id))) {
                $name = $opt->plaintext;
                echo "Get developer: $name\n";
                $developer_id_mysql = saveDeveloper($mysqli, $name, 1);

                //create developer directory for store complexes
                $developer_path = $save_dir . "/" . $name;
                set_error_handler(create_function(
                    '$severity, $message, $file, $line',
                    'throw new ErrorException($message, $severity, $severity, $file, $line);'
                ));

                try {
                    $developer_path = iconv("UTF-8", "Windows-1251", $developer_path);
                } catch (Exception $e) {
                    $developer_path = urldecode($developer_path);
                }
                restore_error_handler();


                createDir($developer_path);

                // Find all complex of developer
                $url = 'http://krisha.kz/complex/search?das[build.comppany]=' . $developer_id . '&das[cityId]=all';
                $developer_html = GetDom($url);
                while ($developer_html == false) {
                    $developer_html = GetDom($url);
                }

                $pager = $developer_html->find('table[id=pager] tr span');
                if (count($pager) > 0) {
                    foreach ($pager as $page) {
                        if (is_numeric(trim($page->plaintext))) {
                            GetDeveloperComplex($developer_id, trim($page->plaintext), $developer_path, $mysqli, $developer_id_mysql);
                        }
                    }
                } else {
                    GetDeveloperComplex($developer_id, 0, $developer_path, $mysqli, $developer_id_mysql);
                }
                echo "\n";
            }

        }
    }
}

?>