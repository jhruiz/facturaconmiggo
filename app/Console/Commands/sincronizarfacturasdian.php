<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use Illuminate\Http\Request;
use GuzzleHttp\Client;
use App\Models\Factura;
use App\Models\Empresa;
use App\Models\Deposito;
use App\Models\Cliente;
use App\Models\FacturaCuentaValore;
use App\Models\Cuentascliente;
use App\Models\Facturasdetalle;
use DateTime; 

class sincronizarfacturasdian extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:sincronizarfacturas';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Proceso que toma las facturas de miggo y las sincroniza con la Dian';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {

      // Obtienen las empresas configuradas para sincronizar facturas con la DIAN
      $empresas = $this->obtenerEmpresasConfiguradas();

      foreach ($empresas as $empresa) {
          $this->procesarEmpresa($empresa);
      }
     
    }

    /**
     * Procesar la información de la empresas que cuente con resolución de facturación configurada
     */
    private function procesarEmpresa($empresa) {
      // Obtiene la información de la resolución de la empresa
      $infoResolucion = $this->obtenerInfoResolucion($empresa['id']);
  
      // Valida que la empresa tenga configurada la información de la resolución
      if (isset($infoResolucion['id'])) {
          // Llama a función general que genera todos los llamados para la creación de la factura para la DIAN
          $this->generarFacturasDian($infoResolucion, $empresa['tokendian'], $empresa['typedocument'], $empresa['municipio_id'], $empresa['nit']);
      }
    }

  //**//**//**//**//**//**//**//** INICIO CONSULTAS //**//**//**//**//**//**//**//**//**

    /**
     * Obtiene las empresas configuradas para gestión de facturación electrónica
     */
    public function obtenerEmpresasConfiguradas(){

      $syncDian = config('custom.SYNC_DIAN');

      return Empresa::where('syncdian', $syncDian)->get();

    }

    /**
     * Obtiene la información de las facturas que se van enviar hacia la Dian
     */
    public function obtenerFacturas( $empresaId ) {

      return Factura::where('empresa_id', $empresaId)
        ->where('factura', 1)
        ->where('eliminar', 0)
        ->whereNull('dianestado_id')
        ->where('created', '>', now()->startOfDay())
        ->take(30)
        ->get();

    }

    /**
     * Obtiene la información de la resolución
     */
    public function obtenerInfoResolucion( $empresaId ) {

      return Deposito::where('empresa_id', $empresaId)
                          ->where('resolucionfacturacion', '<>', '')
                          ->first();

    } 

    /**
     * Obtiene la información del cliente de la factura
     */
    public function obtenerInfoCliente( $clienteId ) {
      
      return Cliente::where('id', $clienteId)->get();

    }

    /**
     * Obtiene la información del tipo de pago débito
     */
    public function obtenerInfoTipoPagoEfectivo( $facturaId ) {

      return FacturaCuentaValore::where('factura_id', $facturaId)->get();

    }

    /**
     * Obtiene la información del tipo de pago a crédito
     */
    public function obtenerInfoTipoPagoCredito( $facturaId ) {

      return Cuentascliente::where('factura_id', $facturaId)->get();

    }

    /**
     * Obtiene la información del detalle de la factura
     */
    public function obtenerInfoFacturaDetalles( $facturaId ) {

      return Facturasdetalle::where('facturasdetalles.factura_id', $facturaId)
                              ->join('productos', 'productos.id', '=' ,'facturasdetalles.producto_id')
                              ->get();
    }

    /**
     * Actualiza el estado de la factura a procesando, sincronizada o error
     */
    private function actualizarEstadoFactura( $factura_id, $estado_id ) {

      Factura::where('id', $factura_id)
      ->update(['dianestado_id' => $estado_id]);

    }

    /**
     * Actualiza el mensaje de error en la factura
     */
    private function actualizarMensajeFactura( $factura_id,  $mensaje ) {

      Factura::where('id', $factura_id)
      ->update(['mensajedian' => $mensaje]);
    
    }

  //**//**//**//**//**//**//**//** FIN CONSULTAS //**//**//**//**//**//**//**//**//**

  //**//**//**//**//**//**//**//** INICIO FUNCIONES APOYO //**//**//**//**//**//**//**//**//**

  /**
   * Obtiene el número de identificación del cliente
   */
  public function obtenerIdentificacion( $identificacion ) {
    if (strpos($identificacion, '-') !== false) {
      $identificacion = explode('-', $identificacion);
      return str_replace(" ", "", $identificacion['0']);
    } else {
        return str_replace(" ", "", $identificacion);
    } 
  }

  /**
   * Obtiene el tipo de organización basado en su tipo de identificación
   */
  public function obtenerOrganizacion( $tipoIdentificacion ) {

      if (in_array($tipoIdentificacion, [1, 2, 3, 4, 5, 7, 8])) {
          return config('custom.TYPE_ORGANIZATION_NAT');
      }
      
      if (in_array($tipoIdentificacion, [6, 9, 10, 11, 12])) {
          return config('custom.TYPE_ORGANIZATION_JUR');
      }

      return null;

  }

  /**
   * Suma una cantidad de días a una fecha específica
   */
  public function sumarDiasFecha( $fecha, $dias ) {

        $fecha = new DateTime($fecha);

        $fecha->modify("+$dias days");

        return $fecha->format('Y-m-d');

  }

  /**
   * Retorna un arreglo con el impuesto de un producto
   */
  public function obtenerImpuestoPorProducto( $valIva, $valSinImp, $impuesto){

    return [
      'tax_id' => config('custom.TAX'),
      'tax_amount' => $valIva,
      'taxable_amount' => $valSinImp,
      'percent' => number_format($impuesto, 2)
    ];
  }

  /**
   * Retorna un arreglo con los totales de venta con y sin iva
   */
  public function obtenerTotalesVenta( $sumValSinIva, $sumValConIva ) {

    return [
      'line_extension_amount' => round( $sumValSinIva, 2),
      'tax_exclusive_amount' => round( $sumValSinIva, 2),
      'tax_inclusive_amount' => round( $sumValConIva, 2),
      'payable_amount' => round( $sumValConIva, 2)
    ];
  }

  /**
   * Calcular los valores de impuestos y productos
   */
  private function calcularValores( $costoTotal, $descuento, $impuesto ) {

    if ( $impuesto > 0 ) {

        $impuesto /= 100;
        $valSinImp = round( ( $costoTotal - $descuento ) / ( 1 + $impuesto ), 2 );
        $valIva = round( ( $costoTotal - $descuento ) - $valSinImp, 2 );
    
    } else {
        $valSinImp = round( ( $costoTotal - $descuento ), 2 );
        $valIva = number_format( 0, 2 );
    }

    return [number_format($valSinImp, 2, '.', ''), number_format($valIva, 2, '.', '')];
  }

  /**
   * Genera la información de las lineas de la factura
   */
  public function obtenerDetalleLineas($val, $arrImpuestos)
  {
      // Inicializa el array de productos con los valores proporcionados
      $arrProductos = [
          'unit_measure_id' => config('custom.UNIT_MEASURE'),
          'invoiced_quantity' => $val['cantidad'],
          'line_extension_amount' => $arrImpuestos['taxable_amount'],
          'free_of_charge_indicator' => config('custom.FREE_OF_CHARGE_INDICATOR'),
          'tax_totals' => [$arrImpuestos],
          'description' => $val['descripcion'],
          'code' => $val['codigo'],
          'type_item_identification_id' => config('custom.TYPE_ITEM_IDENTIFICATION'),
          'price_amount' => $val['costoventa'],
          'base_quantity' => $val['cantidad']
      ];
  
      // Verifica si hay descuento y lo añade al array de productos si es necesario
      if ($val['descuento'] > 0) {
          $arrProductos['allowance_charges']['0'] = [
              "discount_id" => config('custom.ALLOWANCE_CHARGES_DISCOUNT'),
              "charge_indicator" => config('custom.ALLOWANCE_CHARGES_INDICATOR'),
              "allowance_charge_reason" => config('custom.ALLOWANCE_CHARGES_REASON'),
              "amount" => $val['descuento'],
              "base_amount" => $val['costototal']
          ];
      }
  
      return $arrProductos;
  }

  /**
   * Obtiene las cabeceras necesarias para consumir el servicio de sincronizar las factuas con la DIAN
   */
  private function obtenerCabeceras( $token ){

    return [
      'Content-Type' => 'application/json',
      'Accept' => 'application/json',
      'Authorization' => 'Bearer ' . $token
      ];

  }

  function validateArrays(...$arrays){
      foreach ($arrays as $array) {
          if (!is_array($array) || empty($array)) {
              return false;
          }
      }
      return true;
  }
  

  //**//**//**//**//**//**//**//** FIN FUNCIONES APOYO //**//**//**//**//**//**//**//**//**


  //**//**//**//**//**//**//**//** INICIO GENERACION FACTURAS //**//**//**//**//**//**//**//**//**

    /**
     * Genera la información de la resolución de la factura
     */
    public function generarInfoResolucion( $infoResolucion, $factura, $typeDocument ) {

      //Se obtiene la fecha de la factura
      $date = date_create($factura['created']);

      return [
        "number" => $factura['consecutivodian'],
        "prefix" => $infoResolucion['prefijo'],
        "type_document_id" =>  $typeDocument,
        "date" =>  date_format($date, 'Y-m-d'),
        "time" =>  date_format($date, 'H:i:s'),
        "resolution_number" =>  $infoResolucion['resolucionfacturacion']
      ];

    }

    /**
     * Genera la información del cliente de la factura
     */
    public function generarInfoCliente($cliente_id, $municipio_id) {

      $infoCliente = $this->obtenerInfoCliente($cliente_id);

      if ( !isset( $infoCliente['0'] ) ) { 
          return $this->formatearInfoCliente([
              'nit' => '1234567890',
              'nombre' => 'Cliente anónimo',
              'celular' => '3101234567',
              'direccion' => 'Calle 1 # 2 - 3',
              'email' => 'noemail@hotmail.com',
              'tipoidentificacione_id' => '3'
          ], $municipio_id);
      }
  
      return $this->formatearInfoCliente($infoCliente[0], $municipio_id);
  }
  
  /**
   * Genera la información del cliente siempre y cuando se encuentre relacionado 
   * a la factura en la base de datos
   */
  private function formatearInfoCliente($infoCliente, $municipio_id) {
      return [
        "customer" => [
          "identification_number" => !empty($infoCliente['nit']) ? $this->obtenerIdentificacion($infoCliente['nit']) : '1234567890',
          "name" => !empty($infoCliente['nombre']) ? $infoCliente['nombre'] : 'Cliente anónimo',
          "phone" => !empty($infoCliente['celular']) ? $infoCliente['celular'] : '3101234567',
          "address" => !empty($infoCliente['direccion']) ? $infoCliente['direccion'] : 'Calle 1 # 2 - 3',
          "email" => !empty($infoCliente['email']) ? $infoCliente['email'] : 'noemail@hotmail.comm',
          "merchant_registration" => config('custom.MERCHANT_REGISTRATION'),
          "type_document_identification_id" => $infoCliente['tipoidentificacione_id'],
          "type_organization_id" => $this->obtenerOrganizacion($infoCliente['tipoidentificacione_id']),
          "municipality_id" => $municipio_id,
          "type_regime_id" => $this->obtenerOrganizacion($infoCliente['tipoidentificacione_id'])
        ]
      ];
  }


    /**
     * Genera la información del tipo de pago de la factura
     */
    public function generarInfoTipoPago( $facturaId ) {

      $infoTipoPago = $this->obtenerInfoTipoPagoEfectivo( $facturaId );

      if( isset( $infoTipoPago['0'] ) ){

        return [
          "payment_form" => [
            "payment_form_id" => config('custom.PAYMENT_FORM_EF'),
            "payment_method_id" => config('custom.PAYMENT_METHOD_EF')
          ]
        ];

      } else {
        
        $infoTipoPago = $this->obtenerInfoTipoPagoCredito( $facturaId );
        
        if( isset( $infoTipoPago['0'] ) ) {

          $date = date_create($infoTipoPago['0']['created']);

          return [
            "payment_form" => [
              "payment_form_id" => config('custom.PAYMENT_FORM_CR'),
              "payment_method_id" => config('custom.PAYMENT_METHOD_CR'),
              "payment_due_date" => $this->sumarDiasFecha(date_format($date, 'Y-m-d'), config('custom.DURATION_MEASURE')),
              "duration_measure" => config('custom.DURATION_MEASURE')
            ]
          ];

        }

      }

      return [
          "payment_form_id" => config('custom.PAYMENT_FORM_EF'),
          "payment_method_id" => config('custom.PAYMENT_FORM_IND')
      ];

    }

    /**
     * Genera la información general de la factura
     */
    public function generarInfoPagoGeneral( $facturaId ) {

      $infoFacturaDetalle = $this->obtenerInfoFacturaDetalles( $facturaId );

      if ( !isset( $infoFacturaDetalle['0'] ) ) {
        return false;
      }
      
      $sumValSinIva = 0;
      $sumValConIva = 0;
      $arrImpuestos = [];
      $arrLineas = [];

      foreach ( $infoFacturaDetalle as $val ) {

        $costoTotal = $val['costototal'];
        $descuento = $val['descuento'];
        $impuesto = $val['impuesto'];

        list($valSinImp, $valIva) = $this->calcularValores($costoTotal, $descuento, $impuesto);

        // Suma de valores con y sin IVA
        $sumValSinIva += $valSinImp;
        $sumValConIva += round( ( $costoTotal - $descuento ), 2 );

        // Información de los impuestos por cada producto
        $arrImpuestos[] = $this->obtenerImpuestoPorProducto( $valIva, $valSinImp, $val['impuesto'] );

        // Información detallada de las lineas
        $arrLineas[] = $this->obtenerDetalleLineas( $val, $arrImpuestos[count($arrImpuestos) - 1] );
        
      }

      // Información de los totales de la venta con y sin iva
      $arrTotales = $this->obtenerTotalesVenta( $sumValSinIva, $sumValConIva );

      return ['legal_monetary_totals' => $arrTotales, 'tax_totals' => $arrImpuestos, 'invoice_lines' => $arrLineas];

    }

    /**
     * Consumir el servicio de facturación de la DIAN
     */
    private function sincronizarDian( $body, $token, $factura_id ) {

      $client = new Client();
      $headers = $this->obtenerCabeceras($token);
  
      try {
          $response = $client->request('POST', config('custom.API_DIAN') . 'ubl2.1/invoice', [
              'body' => $body,
              'headers' => $headers
          ]);
  
          $resp = json_decode($response->getBody()->getContents());
  
          // Valida si se obtiene un error al enviar a la DIAN  
          if (isset($resp->ResponseDian->Envelope->Body->SendBillSyncResponse->SendBillSyncResult->IsValid) &&
              $resp->ResponseDian->Envelope->Body->SendBillSyncResponse->SendBillSyncResult->IsValid == 'false') {
  
              $mensaje = $resp->ResponseDian->Envelope->Body->SendBillSyncResponse->SendBillSyncResult->ErrorMessage->string;
  
              $this->actualizarEstadoFactura($factura_id, config('custom.DIAN_ESTADO_ERROR'));
              $this->actualizarMensajeFactura($factura_id, $mensaje);
              return;
          }
  
          $this->actualizarEstadoFactura($factura_id, config('custom.DIAN_ESTADO_SINCRONIZADA'));
      } catch (RequestException $e) {
          // Maneja errores de solicitudes HTTP
          $this->actualizarEstadoFactura($factura_id, config('custom.DIAN_ESTADO_ERROR'));
          $this->actualizarMensajeFactura($factura_id, $e->getMessage());
      } catch (GuzzleException $e) {
          // Maneja otros errores de Guzzle
          $this->actualizarEstadoFactura($factura_id, config('custom.DIAN_ESTADO_ERROR'));
          $this->actualizarMensajeFactura($factura_id, $e->getMessage());
      } catch (\Exception $e) {
          // Maneja cualquier otro tipo de excepción
          $this->actualizarEstadoFactura($factura_id, config('custom.DIAN_ESTADO_ERROR'));
          $this->actualizarMensajeFactura($factura_id, $e->getMessage());
      }
        
    }

    /**
     * Envía el correo de la factura al cliente
     */
    private function enviarCorreoFactura( $nitEmpresa, $prefijo, $consecutivodian, $token ) {
      
      $client = new Client();
      $headers = $this->obtenerCabeceras($token);
      
      $body = array(
        'company_idnumber' => $this->obtenerIdentificacion( $nitEmpresa ),
        'prefix' => $prefijo,
        'number' => $consecutivodian
      );

      $response = $client->request('POST', config('custom.API_DIAN') . 'send-email-customer', [
        'body' => json_encode($body),
        'headers' => $headers
      ]);

    }


    /**
     * Genera la información necesaria para enviar las facturas a la Dian
     */
    public function generarFacturasDian( $infoResolucion, $token, $typeDocument, $municipio_id, $nitEmpresa ) {
      
      //Se obtiene la información de las facturas
      $facturas = $this->obtenerFacturas( $infoResolucion['empresa_id'] );

      if( !isset( $facturas['0'] ) ) {
        return false;
      }

      $prevBalance = ['previous_balance' => config('custom.PREVIOUS_BALANCE')];

      foreach( $facturas as $val ) {

        //Actualiza el estado de la factura
        $this->actualizarEstadoFactura( $val['id'], config('custom.DIAN_ESTADO_PROCESANDO') );

        //Organiza la información de la resolución
        $infoRes = $this->generarInfoResolucion( $infoResolucion, $val, $typeDocument );
        
        //Obtiene la información del cliente de la factura
        $infoCliente = $this->generarInfoCliente( $val['cliente_id'], $municipio_id );
        
        //Obtiene la información del tipo de pago
        $infoTipoPago = $this->generarInfoTipoPago( $val['id'] );
        
        //Obtiene los totales generales de la factura
        $infoPagoGeneral = $this->generarInfoPagoGeneral( $val['id'] );

        //Valida que todos los resultados sean un array procesable
        if ($this->validateArrays($infoRes, $infoCliente, $infoTipoPago, $prevBalance, $infoPagoGeneral)) {
          $jsonFactura = json_encode(array_merge($infoRes, $infoCliente, $infoTipoPago, $prevBalance, $infoPagoGeneral));
          $resp = $this->sincronizarDian( $jsonFactura, $token, $val['id'] );

          //Realiza el envío del correo
          $this->enviarCorreoFactura( $nitEmpresa, $infoResolucion['prefijo'], $val['consecutivodian'], $token );
        } else {
          // Actualiza el estado de la factura
          $this->actualizarEstadoFactura( $val['id'], config('custom.DIAN_ESTADO_ERROR') );
          $this->actualizarMensajeFactura( $val['id'] , config('custom.MENSAJE_ERROR'));
        }

      }

    }

  //**//**//**//**//**//**//**//** FIN GENERACION FACTURAS //**//**//**//**//**//**//**//**//**


}
