<?php
// includes/PDFGenerator.php
require_once('fpdf/fpdf.php');

class PDFGenerator extends FPDF {
    // Atributos personalizados
    private $empresa = "Inmobiliaria MH";
    private $logo = "";
    
    // Constructor
    function __construct() {
        parent::__construct();
        $this->SetAutoPageBreak(true, 15);
    }
    
    // Cabecera del PDF
    function Header() {
        // Logo (opcional)
        if (!empty($this->logo) && file_exists($this->logo)) {
            $this->Image($this->logo, 10, 8, 30);
        }
        
        // Título de la empresa
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 10, utf8_decode($this->empresa), 0, 1, 'C');
        
        // Línea separadora
        $this->SetDrawColor(200, 200, 200);
        $this->Line(10, 25, 200, 25);
        
        // Salto de línea
        $this->Ln(10);
    }
    
    // Pie de página
    function Footer() {
        // Posición a 1.5 cm del final
        $this->SetY(-15);
        
        // Fuente
        $this->SetFont('Arial', 'I', 8);
        
        // Número de página
        $this->Cell(0, 10, utf8_decode('Página ') . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
    
    // Método para generar contrato de compraventa
    function generarContratoCompraventa($datos) {
        $this->AddPage();
        
        // Título principal
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 10, utf8_decode('CONTRATO DE COMPRAVENTA DE INMUEBLE'), 0, 1, 'C');
        $this->Ln(5);
        
        // Información general
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 7, utf8_decode('Fecha: ' . date('d/m/Y')), 0, 1);
        $this->Cell(0, 7, utf8_decode('Ciudad: ' . ($datos['address_state'] ?? 'N/A')), 0, 1);
        $this->Ln(5);
        
        // Sección: Declaraciones
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, utf8_decode('DECLARACIONES'), 0, 1);
        $this->Ln(3);
        
        $this->SetFont('Arial', '', 10);
        $this->MultiCell(0, 6, utf8_decode(
            'PRIMERA. - El VENDEDOR declara ser legítimo propietario del inmueble ' .
            'ubicado en: ' . ($datos['address_street'] ?? '') . ' ' . 
            ($datos['address_number'] ?? '') . ', ' .
            ($datos['address_colony'] ?? '') . ', ' .
            ($datos['address_municipality'] ?? '') . ', ' .
            ($datos['address_state'] ?? '') . ', C.P. ' . 
            ($datos['address_zip'] ?? '')
        ));
        $this->Ln(3);
        
        $this->MultiCell(0, 6, utf8_decode(
            'SEGUNDA. - El inmueble objeto de este contrato cuenta con las siguientes ' .
            'características: ' . ($datos['description'] ?? 'No especificadas')
        ));
        $this->Ln(3);
        
        // Precio
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 8, utf8_decode('PRECIO DE VENTA'), 0, 1);
        $this->SetFont('Arial', '', 10);
        
        $precio = isset($datos['asking_price']) ? 
                  '$' . number_format($datos['asking_price'], 2) . ' M.N.' : 
                  'No especificado';
        $this->Cell(0, 7, utf8_decode('El precio de venta acordado es: ' . $precio), 0, 1);
        $this->Ln(5);
        
        // Comisión
        if (isset($datos['commission_percentage'])) {
            $this->Cell(0, 7, utf8_decode(
                'Comisión inmobiliaria: ' . $datos['commission_percentage'] . '%'
            ), 0, 1);
            $this->Ln(3);
        }
        
        // Cláusulas
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, utf8_decode('CLÁUSULAS'), 0, 1);
        $this->Ln(3);
        
        $this->SetFont('Arial', '', 10);
        
        $clausulas = [
            'PRIMERA. - El VENDEDOR se obliga a transmitir la propiedad del inmueble al COMPRADOR.',
            'SEGUNDA. - El COMPRADOR se obliga a pagar el precio pactado en los términos establecidos.',
            'TERCERA. - La entrega del inmueble se realizará en la fecha acordada por ambas partes.',
            'CUARTA. - Todos los gastos de escrituración serán cubiertos según lo acordado.'
        ];
        
        foreach ($clausulas as $clausula) {
            $this->MultiCell(0, 6, utf8_decode($clausula));
            $this->Ln(2);
        }
        
        // Firmas
        $this->Ln(20);
        
        // Firma Vendedor
        $this->SetY($this->GetY() + 10);
        $y = $this->GetY();
        
        $this->Line(30, $y, 80, $y);
        $this->Line(130, $y, 180, $y);
        
        $this->SetY($y + 2);
        $this->Cell(80, 5, utf8_decode('VENDEDOR'), 0, 0, 'C');
        $this->Cell(50, 5, '', 0, 0, 'C');
        $this->Cell(80, 5, utf8_decode('COMPRADOR'), 0, 1, 'C');
    }
    
    // Método para generar poder notarial
    function generarPoderNotarial($datos) {
        $this->AddPage();
        
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 10, utf8_decode('PODER NOTARIAL'), 0, 1, 'C');
        $this->Ln(5);
        
        $this->SetFont('Arial', '', 10);
        $this->MultiCell(0, 6, utf8_decode(
            'En la ciudad de ' . ($datos['address_state'] ?? '') . ', a ' . 
            date('d') . ' días del mes de ' . date('F') . ' del año ' . date('Y') . ', ' .
            'comparece el señor(a) ' . ($datos['initiated_by_name'] ?? '') . ' ' . 
            ($datos['initiated_by_lastname'] ?? '') . ', quien otorga PODER GENERAL ' .
            'para actos de administración en relación con el inmueble ubicado en ' .
            ($datos['address_street'] ?? '') . ' ' . ($datos['address_number'] ?? '') . ', ' .
            ($datos['address_colony'] ?? '') . ', ' . ($datos['address_municipality'] ?? '') . ', ' .
            ($datos['address_state'] ?? '')
        ));
        
        $this->Ln(20);
        $this->Cell(0, 5, utf8_decode('_______________________'), 0, 1, 'C');
        $this->Cell(0, 5, utf8_decode('Firma del Poderdante'), 0, 1, 'C');
    }
}
?>