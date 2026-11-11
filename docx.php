<?php
/*
 * Шаблоны Word (.docx) с полями в фигурных скобках: {ФИО кандидата}, {Дата прохода} …
 * Word часто разрезает текст поля на несколько кусков с разным оформлением («{ФИО» + « кандидата}»),
 * поэтому поля ищутся по тексту всего абзаца, а замена вписывается в первый кусок — с его оформлением.
 * Нужен только ZipArchive и DOM (есть на любом хостинге с PHP).
 */

const DOCX_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
const DOCX_FIELD_RE = '/\{([^{}\r\n]{1,80})\}/u';

/** Части документа, где бывает текст: основной текст, колонтитулы, сноски */
function docx_parts(ZipArchive $z): array {
    $parts = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = $z->getNameIndex($i);
        if (preg_match('#^word/(document|header\d*|footer\d*|footnotes|endnotes)\.xml$#', $n)) $parts[] = $n;
    }
    usort($parts, fn($a, $b) => ($a === 'word/document.xml' ? -1 : ($b === 'word/document.xml' ? 1 : strcmp($a, $b))));
    return $parts;
}

/** Открыть .docx из строки. null — это не документ Word. */
function docx_open(string $bin, ?string &$tmp): ?ZipArchive {
    $tmp = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($tmp, $bin);
    $z = new ZipArchive();
    if ($z->open($tmp) !== true || $z->locateName('word/document.xml') === false) {
        @unlink($tmp);
        return null;
    }
    return $z;
}

/**
 * Пройти по абзацам XML-части: $cb(имя поля) возвращает замену или null (оставить как есть).
 * Возвращает новый XML или null, если ничего не менялось.
 */
function docx_walk(string $xml, callable $cb, ?array &$names = null): ?string {
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = true;
    if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE)) return null;
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', DOCX_W);

    // куски текста <w:t>, сгруппированные по ближайшему абзацу <w:p>
    $groups = []; $keep = [];
    foreach ($xp->query('//w:t') as $t) {
        $p = $t->parentNode;
        while ($p && !($p->localName === 'p' && $p->namespaceURI === DOCX_W)) $p = $p->parentNode;
        if (!$p) continue;
        $keep[] = $p;                       // держим ссылку, иначе номер объекта может достаться другому абзацу
        $groups[spl_object_id($p)][] = $t;
    }
    $changed = false;
    foreach ($groups as $ts) {
        $text = ''; $starts = [];
        foreach ($ts as $i => $t) { $starts[$i] = strlen($text); $text .= $t->nodeValue; }
        if (strpos($text, '{') === false) continue;
        if (!preg_match_all(DOCX_FIELD_RE, $text, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) continue;
        if ($names !== null) foreach ($mm as $m) $names[trim($m[1][0])] = true;
        // с конца, чтобы смещения ещё не заменённых полей оставались верными
        foreach (array_reverse($mm) as $m) {
            $val = $cb(trim($m[1][0]));
            if ($val === null) continue;
            $from = $m[0][1]; $to = $from + strlen($m[0][0]);
            $vals = array_map(fn($t) => $t->nodeValue, $ts);
            $si = $ei = null;
            foreach ($ts as $i => $t) {
                $a = $starts[$i]; $b = $a + strlen($vals[$i]);
                if ($si === null && $from >= $a && $from < $b) $si = $i;
                if ($to > $a && $to <= $b) { $ei = $i; break; }
            }
            if ($si === null || $ei === null) continue;
            $head = substr($vals[$si], 0, $from - $starts[$si]);
            $tail = substr($vals[$ei], $to - $starts[$ei]);
            if ($si === $ei) {
                docx_set_text($ts[$si], $head . $val . $tail);
            } else {
                docx_set_text($ts[$si], $head . $val);
                for ($k = $si + 1; $k < $ei; $k++) docx_set_text($ts[$k], '');
                docx_set_text($ts[$ei], $tail);
            }
            // пересчитать смещения после правки
            $text = ''; foreach ($ts as $i => $t) { $starts[$i] = strlen($text); $text .= $t->nodeValue; }
            $changed = true;
        }
    }
    if (!$changed) return null;
    // переносы строк в значениях → разрывы строк Word
    foreach (iterator_to_array($xp->query('//w:t')) as $t) {
        if (strpos($t->nodeValue, "\n") === false) continue;
        $lines = explode("\n", str_replace("\r", '', $t->nodeValue));
        docx_set_text($t, array_shift($lines));
        $after = $t;
        foreach ($lines as $line) {
            $br = $dom->createElementNS(DOCX_W, 'w:br');
            $nt = $dom->createElementNS(DOCX_W, 'w:t');
            docx_set_text($nt, $line);
            $after->parentNode->insertBefore($br, $after->nextSibling);
            $br->parentNode->insertBefore($nt, $br->nextSibling);
            $after = $nt;
        }
    }
    return $dom->saveXML();
}

function docx_set_text(DOMElement $t, string $s): void {
    $t->nodeValue = '';
    $t->appendChild($t->ownerDocument->createTextNode($s));
    $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
}

/** Список полей шаблона в порядке появления (без повторов). null — файл не читается как .docx */
function docx_fields(string $bin): ?array {
    $z = docx_open($bin, $tmp);
    if (!$z) return null;
    $found = [];
    foreach (docx_parts($z) as $part) {
        docx_walk((string)$z->getFromName($part), fn($name) => null, $found);
    }
    $z->close();
    @unlink($tmp);
    return array_keys($found);
}

/**
 * Заполнить шаблон: $values — [имя поля => текст]. Поля, которых нет в $values, остаются как есть.
 * $banner — строка-пометка вверху документа (тестовый режим).
 */
function docx_fill(string $bin, array $values, ?string $banner = null): ?string {
    $z = docx_open($bin, $tmp);
    if (!$z) return null;
    foreach (docx_parts($z) as $part) {
        $xml = (string)$z->getFromName($part);
        $new = docx_walk($xml, fn($name) => array_key_exists($name, $values) ? (string)$values[$name] : null);
        if ($part === 'word/document.xml' && $banner !== null) $new = docx_add_banner($new ?? $xml, $banner);
        if ($new !== null) $z->addFromString($part, $new);
    }
    $z->close();
    $out = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $out;
}

/** Красная строка-пометка первым абзацем документа */
function docx_add_banner(string $xml, string $text): string {
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = true;
    if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE)) return $xml;
    $body = $dom->getElementsByTagNameNS(DOCX_W, 'body')->item(0);
    if (!$body) return $xml;
    $frag = $dom->createDocumentFragment();
    $frag->appendXML('<w:p xmlns:w="' . DOCX_W . '"><w:pPr><w:jc w:val="center"/><w:spacing w:after="120"/></w:pPr>'
        . '<w:r><w:rPr><w:b/><w:color w:val="C00000"/><w:sz w:val="20"/></w:rPr><w:t xml:space="preserve">'
        . htmlspecialchars($text, ENT_XML1, 'UTF-8') . '</w:t></w:r></w:p>');
    $body->insertBefore($frag, $body->firstChild);
    return $dom->saveXML();
}
