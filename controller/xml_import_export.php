<?php

/*
 * This file is part of xml_import_export
 * Copyright (C) 2014-2017  Carlos Garcia Gomez  neorazorx@gmail.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

class xml_import_export extends fs_controller {

   public function __construct() {
      parent::__construct('xml_import_export', 'Importar/exportar XML', 'admin');
   }

   protected function private_core() {
      if (isset($_GET['table'])) {
         $this->export_structure_xml($_GET['table']);
      } else if (isset($_POST['where'])) {
         $this->export_xml();
      } else if (isset($_POST['archivo'])) {
         $this->import_xml();
      }
   }

   public function tablas() {
      return $this->db->list_tables();
   }

   private function export_xml() {
      /// desactivamos el motor de plantillas
      $this->template = FALSE;

      /// creamos el xml
      $cadena_xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
<!--
    Document   : tabla_" . $_POST['tabla'] . ".xml
    Description:
        Datos de la tabla " . $_POST['tabla'] . ".
-->

<tabla>
</tabla>\n";

      $archivo_xml = simplexml_load_string($cadena_xml);

      $data = $this->db->select("SELECT * FROM " . $_POST['tabla'] . " WHERE " . $_POST['where'] . ";");
      if ($data) {
         $archivo_xml->addChild('nombre', $_POST['tabla']);
         $columnas = TRUE;

         foreach ($data as $d) {
            if ($columnas) {
               $columns = array_keys($d);
               $aux0 = $archivo_xml->addChild('columnas');
               foreach ($columns as $c) {
                  $aux0->addChild('columna', $c);
               }

               $columnas = FALSE;
            }

            $aux1 = $archivo_xml->addChild('fila');
            foreach ($d as $i => $value) {
               if (is_null($value)) {
                  $aux1->addChild($i, 'NULL');
               } else if ($value == 't') {
                  $aux1->addChild($i, 'TRUE');
               } else if ($value == 'f') {
                  $aux1->addChild($i, 'FALSE');
               } else {
                  $aux1->addChild($i, base64_encode($value));
               }
            }
         }
      }

      /// volcamos el XML
      header("content-type: application/xml; charset=UTF-8");
      header('Content-Disposition: attachment; filename="tabla_' . $_POST['tabla'] . '.xml"');
      echo $archivo_xml->asXML();
   }

   private function import_xml() {
      if (is_uploaded_file($_FILES['farchivo']['tmp_name'])) {
         $xml = simplexml_load_file($_FILES['farchivo']['tmp_name']);
         if ($xml) {
            $tabla = $xml->nombre;
            $continuar = TRUE;

            if (!$this->db->table_exists($tabla)) {
               if (file_exists('model/table/' . $tabla . '.xml')) {
                  $xml_columnas = array();
                  $xml_restricciones = array();
                  if ($this->get_xml_table($tabla, $xml_columnas, $xml_restricciones)) {
                     /*
                      * Ejecutamos el SQL para generar la tabla, pero sin las restricciones,
                      * así que no hace falta insertar los datos en orden
                      */
                     if (!$this->db->exec($this->db->generate_table($tabla, $xml_columnas, $xml_restricciones))) {
                        $this->new_error_msg('Error al comprobar la tabla ' . $tabla);
                        $continuar = FALSE;
                     }
                  } else {
                     $this->new_error_msg('Error en model/table/' . $tabla . '.xml');
                     $continuar = FALSE;
                  }
               } else {
                  $this->new_error_msg('La tabla no existe y no encuentro el archivo model/table/' . $tabla . '.xml');
                  $this->new_error_msg('Si esta tabla pertenece a un plugin, instálalo, activalo y vuelve a intentarlo.');
                  $continuar = FALSE;
               }
            }

            if ($continuar) {
               /// obetenemos las columnas de los datos almacenados en el xml
               $columnas = array();
               if ($xml->columnas) {
                  /// pero sólo queremos las columnas comunes con la tabla de la base de datos
                  foreach ($this->db->get_columns($tabla) as $col1) {
                     foreach ($xml->columnas->columna as $col2) {
                        if ($col1['column_name'] == $col2) {
                           $columnas[] = $col2;
                           break;
                        }
                     }
                  }
               }

               if ($xml->fila) {
                  $total = 0;
                  $fail = 0;

                  foreach ($xml->fila as $f) {
                     $filas = array();
                     foreach ($columnas as $col) {
                        if (in_array($f->$col, array('NULL', 'FALSE', 'TRUE'))) {
                           $filas[] = $f->$col;
                        } else {
                           $filas[] = $this->empresa->var2str(base64_decode($f->$col));
                        }
                     }

                     if ($this->db->exec("INSERT INTO " . $tabla . " (" . join(',', $columnas) . ") VALUES (" . join(',', $filas) . ");")) {
                        $total++;
                     } else {
                        $fail++;
                     }
                  }

                  $this->new_message($total . ' filas insertadas en ' . $tabla . '. ' . $fail . ' errores.');
                  $this->cache->clean();
               }
            }
         }
      }
   }

   /// obtiene las columnas y restricciones del fichero xml para una tabla
   private function get_xml_table($table_name, &$columnas, &$restricciones) {
      $retorno = FALSE;
      $filename = 'model/table/' . $table_name . '.xml';

      if (file_exists($filename)) {
         $xml = simplexml_load_string(file_get_contents('./' . $filename, FILE_USE_INCLUDE_PATH));
         if ($xml) {
            if ($xml->columna) {
               $i = 0;
               foreach ($xml->columna as $col) {
                  $columnas[$i]['nombre'] = $col->nombre;
                  $columnas[$i]['tipo'] = $col->tipo;
                  $columnas[$i]['nulo'] = $col->nulo;

                  if ($col->defecto == '') {
                     $columnas[$i]['defecto'] = NULL;
                  } else {
                     $columnas[$i]['defecto'] = $col->defecto;
                  }

                  $i++;
               }

               /// debe de haber columnas, sino es un fallo
               $retorno = TRUE;
            }

            if ($xml->restriccion) {
               $i = 0;
               foreach ($xml->restriccion as $col) {
                  $restricciones[$i]['nombre'] = $col->nombre;
                  $restricciones[$i]['consulta'] = $col->consulta;
                  $i++;
               }
            }
         } else {
            $this->new_error_msg('Error al leer el archivo ' . $filename);
         }
      } else {
         $this->new_error_msg('Archivo ' . $filename . ' no encontrado.');
      }

      return $retorno;
   }

   public function all_tables() {
      return $this->db->list_tables();
   }

   public function export_structure_xml($table) {
      /// desactivamos la renderización del template
      $this->template = FALSE;

      $this->cadena_xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
<!--
    Document   : " . $table . ".xml
    Description:
        Estructura de la tabla " . $table . ".
-->

<tabla>
</tabla>\n";

      /// creamos el xml
      $this->archivo_xml = simplexml_load_string($this->cadena_xml);
      $columnas = Array();
      $restricciones = Array();
      if ($this->db->table_exists($table)) {
         $columnas = $this->db->get_columns($table);
         $restricciones = $this->db->get_constraints($table, TRUE);

         if ($columnas) {
            foreach ($columnas as $col) {
               $aux = $this->archivo_xml->addChild('columna');
               $aux->addChild('nombre', $col['name']);

               /// comprobamos si es auto_increment
               $auto_increment = FALSE;
               if (isset($col['extra'])) {
                  if ($col['extra'] == 'auto_increment') {
                     $auto_increment = TRUE;
                  }
               }

               if ($auto_increment) {
                  $aux->addChild('tipo', 'serial');

                  if ($col['is_nullable'] == 'YES') {
                     $aux->addChild('nulo', 'YES');
                  } else {
                     $aux->addChild('nulo', 'NO');
                  }

                  $aux->addChild('defecto', "nextval('" . $table . '_' . $col['name'] . "_seq'::regclass)");
               } else if ($col['type'] == 'integer' AND $col['default'] == "nextval('" . $table . '_' . $col['name'] . "_seq'::regclass)") {
                  $aux->addChild('tipo', 'serial');

                  if ($col['is_nullable'] == 'YES') {
                     $aux->addChild('nulo', 'YES');
                  } else {
                     $aux->addChild('nulo', 'NO');
                  }

                  $aux->addChild('defecto', $col['default']);
               } else {
                  $aux->addChild('tipo', $this->dbtype_to_xmltype($col['type']));

                  if ($col['is_nullable'] == 'YES') {
                     $aux->addChild('nulo', 'YES');
                  } else {
                     $aux->addChild('nulo', 'NO');
                  }

                  if (isset($col['default'])) {
                     $aux->addChild('defecto', $col['default']);
                  }
               }
            }
         }

         if ($restricciones) {
            $primary_key_name = '';
            $primary_key_cols = array();
            $uniques = array();
            foreach ($restricciones as $col) {
               if ($col['type'] == 'PRIMARY KEY') {
                  $primary_key_name = $table . '_pkey';
                  $primary_key_cols[] = $col['column_name'];
               } else if($col['type'] == 'FOREIGN KEY') {
                  $aux = $this->archivo_xml->addChild('restriccion');
                  $aux->addChild('nombre', $col['name']);
                  $aux->addChild('consulta', $col['type'] . ' ('.$col['column_name'].') REFERENCES '.$col['foreign_table_name']
                          .' ('.$col['foreign_column_name'].') ON DELETE '.$col['on_delete'].' ON UPDATE '.$col['on_update']);
               } else if($col['type'] == 'UNIQUE') {
                  $uniques[$col['name']][] = $col['column_name'];
               } else {
                  $aux = $this->archivo_xml->addChild('restriccion');
                  $aux->addChild('nombre', $col['name']);
                  $aux->addChild('consulta', $col['type'] . ' (...)');
               }
            }
            
            if($primary_key_cols) {
               $aux = $this->archivo_xml->addChild('restriccion');
               $aux->addChild('nombre', $primary_key_name);
               $aux->addChild('consulta', 'PRIMARY KEY ('.join(',', $primary_key_cols).')');
            }
            
            foreach($uniques as $key => $value) {
               $aux = $this->archivo_xml->addChild('restriccion');
               $aux->addChild('nombre', $key);
               $aux->addChild('consulta', 'UNIQUE ('.join(',', $value).')');
            }
         }
      }

      header("content-type: application/xml; charset=UTF-8");
      //header('Content-Disposition: attachment; filename="' . $table . '.xml"');
      echo $this->archivo_xml->asXML();
   }
   
   private function dbtype_to_xmltype($value) {
      if( substr($value, 0, 3) == 'int' ) {
         $nvalue = 'integer';
      } else if( substr($value, 0, 7) == 'tinyint' ) {
         $nvalue = 'boolean';
      } else {
         $nvalue = $value;
      }
      
      return $nvalue;
   }

}
