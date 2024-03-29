<?php

namespace SimpleXlsxParser;

use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

use Iterator;
use XMLReader;
use DOMDocument;
use ZipArchive;

class Parser implements Iterator
{
  private $sharedStringsCache = [];
  private $formatsCache = [];

  private $zip = [];
  private $tmp_dir = '';

  private $_activeSheet = 1;
  private $_sheets = [];
  private $_reader = null;
  private $_index = 1;
  const _TAG = 'row';

  public function __construct($inputFileName)
  {
    $this->tmp_dir = ini_get('upload_tmp_dir') ? ini_get('upload_tmp_dir') : sys_get_temp_dir();
    $this->tmp_dir .= '/romkamix_simple_xlsx_parser';

    // Unzip
    $zip = new ZipArchive();
    $zip->open($inputFileName);
    $zip->extractTo($this->tmp_dir);

    if (file_exists($this->tmp_dir . '/xl/workbook.xml'))
    {
      $sheets = simplexml_load_file($this->tmp_dir . '/xl/workbook.xml');

      foreach ($sheets->sheets->sheet as $sheet) {
        $attrs = $sheet->attributes('r', true);

        foreach ($attrs as $name => $value)
        {
          if ($name == 'id')
          {
            $sheet_id = (int)str_replace('rId', '', (string)$value);
            $this->_sheets[$sheet_id] = (string)$sheet['name'];
            break;
          }
        }
      }

      unset($sheets);
    }

    if (file_exists($this->tmp_dir . '/xl/sharedStrings.xml'))
    {
      $sharedStrings = simplexml_load_file($this->tmp_dir . '/xl/sharedStrings.xml');

      foreach ($sharedStrings->si as $sharedString) {
        $this->sharedStringsCache[] = (string)$sharedString->t;
      }

      unset($sharedStrings);
    }

    if (file_exists($this->tmp_dir . '/xl/styles.xml'))
    {
      $styles = simplexml_load_file($this->tmp_dir . '/xl/styles.xml');

      $customFormats = array();

      if ($styles->numFmts)
      {
        foreach ($styles->numFmts->numFmt as $numFmt)
        {
          $customFormats[(int) $numFmt['numFmtId']] = (string)$numFmt['formatCode'];
        }
      }

      if ($styles->cellXfs)
      {
        foreach ($styles->cellXfs->xf as $xf)
        {
          $numFmtId = (int) $xf['numFmtId'];

          if (isset($customFormats[$numFmtId])) {
            $this->formatsCache[] = $customFormats[$numFmtId];
            continue;
          }

          if (in_array($numFmtId, array('14'))) {
            $this->formatsCache[] = 'dd.mm.yyyy';
            continue;
          }

          $this->formatsCache[] = NumberFormat::builtInFormatCode($numFmtId);
        }
      }

      unset($styles);
      unset($customFormats);
    }

    $this->setActiveSheet($this->_activeSheet);
  }

  public function getSheets()
  {
    return $this->_sheets;
  }

  public function setActiveSheet($index)
  {
    if (array_key_exists($index, $this->_sheets))
    {
      $this->_activeSheet = $index;
      $this->rewind();
    }
  }

  public function current()
  {
    $row = array();

    $doc = new DOMDocument;
    $node = simplexml_import_dom($doc->importNode($this->_reader->expand(), true));

    foreach ($node->c as $cell)
    {
      $value = isset($cell->v) ? (string) $cell->v : '';

      if (isset($cell['t']) && $cell['t'] == 's')
      {
        $value = $this->sharedStringsCache[$value];
      }

      if (!empty($value) && isset($cell['s'])
          && isset($this->formatsCache[(string) $cell['s']]))
      {
        $value = NumberFormat::toFormattedString($value, $this->formatsCache[(string) $cell['s']]);
      }

      [$cellColumn, $cellRow] = Coordinate::coordinateFromString($cell['r']);
      $cellColumnIndex = Coordinate::columnIndexFromString($cellColumn);

      $row[$cellColumnIndex] = $value;
    }

    return $row;
  }

  public function key()
  {
    return $this->_index;
  }

  public function next() //: void;
  {
    $this->_reader->next(self::_TAG);
    $this->_index++;
  }

  public function rewind() //: void;
  {
    $this->_reader = new XMLReader;
    $this->_reader->open($this->tmp_dir . '/xl/worksheets/sheet' . $this->_activeSheet . '.xml');

    while ($this->_reader->read() && $this->_reader->name !== self::_TAG);

    $this->_index = 1;
  }

  public function valid() //: bool
  {
    return ($this->_reader->name === self::_TAG);
  }
}