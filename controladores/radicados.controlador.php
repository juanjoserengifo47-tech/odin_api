<?php

require_once __DIR__ . "/../modelos/radicados.modelo.php";
require_once __DIR__ . "/../servicios/archivo.servicio.php";
require_once __DIR__ . "/../api/helpers/respuesta.helper.php";

/**
 * Controlador RESTful para Radicación Documental — Proyecto ODIN
 * 
 * Gestiona el ciclo completo de radicación, validación de datos de entrada,
 * verificación binaria de documentos MIME y coordinación con el servicio
 * de archivos y el modelo de persistencia.
 */
class ControladorRadicados
{
    /**
     * Procesa la radicación de un documento de entrada/salida/interno con adjuntos.
     * Recibe payload de formulario (POST) y archivos adjuntos ($_FILES).
     */
    public static function ctrRadicar(array $post, array $files): void
    {
        $archivosGuardadosTemporalmente = [];

        try {
            // 1. Validar campos mínimos del radicado
            $asunto = trim($post["asuntoDocumento"] ?? ($post["asunto"] ?? ""));
            if (empty($asunto)) {
                RespuestaHelper::error("El asunto del documento es obligatorio.", 400);
                return;
            }

            $tipoRadicacionRaw = trim($post["tipoRadicacion"] ?? ($post["tipo_radicado"] ?? "entrada"));
            $tipoRadicacionMap = [
                "entrada" => "ENTRADA",
                "salida"  => "SALIDA",
                "interna" => "INTERNO",
                "interno" => "INTERNO"
            ];
            $tipoRadicado = $tipoRadicacionMap[strtolower($tipoRadicacionRaw)] ?? "ENTRADA";

            $medioRecepcion = trim($post["canalRecepcion"] ?? ($post["medio_recepcion"] ?? "Ventanilla"));
            $prioridad = strtoupper(trim($post["prioridad"] ?? "MEDIA"));
            if (!in_array($prioridad, ["BAJA", "MEDIA", "ALTA", "URGENTE"], true)) {
                $prioridad = "MEDIA";
            }

            $idTramite     = !empty($post["tramite"]) ? (int)$post["tramite"] : (!empty($post["id_tramite"]) ? (int)$post["id_tramite"] : null);
            $idEstado      = !empty($post["estadoInicial"]) ? (int)$post["estadoInicial"] : (!empty($post["id_estado"]) ? (int)$post["id_estado"] : 1);
            $idDependencia = !empty($post["dependenciaDestino"]) 
                                ? (int)$post["dependenciaDestino"] 
                                : (!empty($post["dependenciaResponsable"]) 
                                    ? (int)$post["dependenciaResponsable"] 
                                    : (!empty($post["id_dependencia"]) ? (int)$post["id_dependencia"] : null));

            // Regla de Negocio: Los radicados se dirigen a dependencias; se asigna automáticamente al encargado del área
            $idUsuario = 1;
            if (!empty($idDependencia)) {
                $encargado = ModeloRadicados::mdlObtenerEncargadoDependencia($idDependencia);
                if ($encargado && !empty($encargado["id_usuario"])) {
                    $idUsuario = (int)$encargado["id_usuario"];
                } elseif (!empty($post["responsable"])) {
                    $idUsuario = (int)$post["responsable"];
                }
            } elseif (!empty($post["responsable"])) {
                $idUsuario = (int)$post["responsable"];
            }

            // Cálculo automático e inmutable de la Fecha Límite según Acuerdo 060/2001 AGN y Ley 1755/2015
            $fechaLimite = null;
            if (!empty($idTramite)) {
                $fechaLimite = self::calcularFechaLimitePorTramite($idTramite);
            }
            if ($fechaLimite === null) {
                $fechaLimite = !empty($post["fechaLimite"]) ? trim($post["fechaLimite"]) : (!empty($post["fecha_limite"]) ? trim($post["fecha_limite"]) : null);
            }

            // 2. Manejo de Ley de Transparencia (Radicación Anónima RF-09)
            $esAnonimo = !empty($post["radicacionAnonima"]) || (isset($post["es_anonimo"]) && ($post["es_anonimo"] === true || $post["es_anonimo"] === "1" || $post["es_anonimo"] === "true"));

            $datosRemitente = null;
            $remitenteNombre = "ANÓNIMO";

            if (!$esAnonimo) {
                $nombreSol = trim($post["nombreSolicitante"] ?? ($post["remitente"] ?? ""));
                $numDoc    = trim($post["numeroDocumento"] ?? ($post["num_identificacion"] ?? ""));
                $correoSol = trim($post["correoSolicitante"] ?? ($post["correo"] ?? ""));
                $tipoDoc   = trim($post["tipoDocumento"] ?? ($post["tipo_identificacion"] ?? "CC"));
                $telSol    = trim($post["telefonoSolicitante"] ?? ($post["telefono"] ?? ""));
                $ciuSol    = trim($post["ciudadSolicitante"] ?? ($post["direccion"] ?? ""));

                if (empty($nombreSol) || empty($numDoc)) {
                    RespuestaHelper::error("Para radicaciones no anónimas, el nombre y número de identificación del solicitante son obligatorios.", 400);
                    return;
                }

                if (!empty($correoSol) && !filter_var($correoSol, FILTER_VALIDATE_EMAIL)) {
                    RespuestaHelper::error("La dirección de correo electrónico del solicitante no tiene un formato válido.", 400);
                    return;
                }

                $remitenteNombre = $nombreSol;
                $datosRemitente = [
                    "tipo_identificacion" => strtoupper($tipoDoc),
                    "num_identificacion"  => $numDoc,
                    "nombre"              => $nombreSol,
                    "apellido"            => "",
                    "nombre_completo"     => $nombreSol,
                    "correo"              => $correoSol,
                    "telefono"            => $telSol,
                    "direccion"           => $ciuSol
                ];
            }

            // 3. Normalizar y validar archivos adjuntos
            $listaArchivos = self::normalizarArchivosSubidos($files);
            if (empty($listaArchivos)) {
                RespuestaHelper::error("Debe adjuntar al menos un documento digital válido (PDF, Excel o imagen) para radicar.", 400);
                return;
            }

            // Prefijo preventivo temporal para el nombrado de archivos antes de la asignación del consecutivo
            $prefijoTemp = "TEMP_" . date("YmdHis");
            $metadatosDocumentos = [];

            foreach ($listaArchivos as $archivoIndividual) {
                // Validación estricta con ArchivoServicio (MIME binario real, límite de peso, hash SHA-256)
                $metaDoc = ArchivoServicio::procesarArchivo($archivoIndividual, $prefijoTemp, "DOC");
                $archivosGuardadosTemporalmente[] = $metaDoc["ruta_archivo"];
                $metadatosDocumentos[] = $metaDoc;
            }

            // 4. Capturar clasificación CCD (Series y Subseries)
            $idSerie = !empty($post["serieDocumental"]) ? (int)$post["serieDocumental"] : (!empty($post["id_serie"]) ? (int)$post["id_serie"] : null);
            $idSubserie = !empty($post["subserieDocumental"]) ? (int)$post["subserieDocumental"] : (!empty($post["id_subserie"]) ? (int)$post["id_subserie"] : null);
            $codigoSerie = !empty($post["codigo_serie"]) ? trim($post["codigo_serie"]) : null;
            $codigoSubserie = !empty($post["codigo_subserie"]) ? trim($post["codigo_subserie"]) : null;

            if ($idSerie && !$codigoSerie) {
                $db = Conexion::conectar();
                $stmtS = $db->prepare("SELECT codigo_serie FROM ccd_series WHERE id_serie = :id");
                $stmtS->execute([":id" => $idSerie]);
                $codigoSerie = $stmtS->fetchColumn() ?: null;
            }
            if ($idSubserie && !$codigoSubserie) {
                $db = Conexion::conectar();
                $stmtSub = $db->prepare("SELECT codigo_subserie FROM ccd_subseries WHERE id_subserie = :id");
                $stmtSub->execute([":id" => $idSubserie]);
                $codigoSubserie = $stmtSub->fetchColumn() ?: null;
            }

            // 5. Estructurar datos para persistencia en base de datos
            $datosRadicado = [
                "asunto"           => $asunto,
                "tipo_radicado"    => $tipoRadicado,
                "medio_recepcion"  => $medioRecepcion,
                "prioridad"        => $prioridad,
                "confidencialidad" => "PUBLICO",
                "id_tramite"       => $idTramite,
                "id_estado"        => $idEstado,
                "id_dependencia"   => $idDependencia,
                "id_usuario"       => $idUsuario,
                "fecha_limite"     => $fechaLimite,
                "es_anonimo"       => $esAnonimo,
                "remitente"        => $remitenteNombre,
                "id_serie"         => $idSerie,
                "id_subserie"      => $idSubserie,
                "codigo_serie"     => $codigoSerie,
                "codigo_subserie"  => $codigoSubserie
            ];

            // 6. Persistencia atómica
            $resultado = ModeloRadicados::mdlRadicarDocumento($datosRadicado, $metadatosDocumentos, $datosRemitente);

            RespuestaHelper::exito(
                $resultado,
                "Documento radicado exitosamente con el número " . $resultado["numero_radicado"],
                201
            );
        } catch (InvalidArgumentException $ie) {
            // Error de validación de negocio o de archivo (MIME, peso)
            self::limpiarArchivosTemporales($archivosGuardadosTemporalmente);
            RespuestaHelper::error($ie->getMessage(), 400);
        } catch (Exception $e) {
            // Error interno de base de datos o sistema
            self::limpiarArchivosTemporales($archivosGuardadosTemporalmente);
            error_log("Error en ControladorRadicados::ctrRadicar: " . $e->getMessage());
            RespuestaHelper::error("Ocurrió un error interno al registrar la radicación: " . $e->getMessage(), 500);
        }
    }

    /**
     * Consulta y retorna el listado de radicados registrados según el perfil del usuario autenticado.
     * - Si el usuario es Administrador, visualiza todos los radicados de la entidad.
     * - Si es un usuario regular, visualiza exclusivamente los radicados bajo su responsabilidad.
     */
    public static function ctrListar(): void
    {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            // 1. Obtener identificador y rol del usuario (desde sesión activa o parámetros GET para tests)
            $idUsuario = isset($_SESSION["id"]) 
                            ? (int)$_SESSION["id"] 
                            : (isset($_GET["id_usuario"]) ? (int)$_GET["id_usuario"] : null);

            $rolSession = $_SESSION["rol"] ?? ($_GET["rol"] ?? null);

            // 2. Determinar si el usuario tiene privilegios de Administrador
            $esAdmin = false;

            if (isset($_GET["es_admin"])) {
                $esAdmin = ($_GET["es_admin"] === "1" || $_GET["es_admin"] === "true");
            } elseif ($rolSession !== null) {
                // Comprobación rápida por ID conocido (1 = Administrador, 35 = Admin) o slug directo
                if ($rolSession == 1 || $rolSession == 35 || strtolower((string)$rolSession) === "admin") {
                    $esAdmin = true;
                } else {
                    // Verificación en base de datos del rol si es un id numérico
                    $db = Conexion::conectar();
                    $stmtRol = $db->prepare("SELECT rol, nombre FROM roles WHERE id_rol = :id_rol LIMIT 1");
                    $stmtRol->bindValue(":id_rol", (int)$rolSession, PDO::PARAM_INT);
                    $stmtRol->execute();
                    $rolData = $stmtRol->fetch(PDO::FETCH_ASSOC);
                    if ($rolData && (strtolower($rolData["rol"]) === "admin" || stripos($rolData["nombre"], "admin") !== false)) {
                        $esAdmin = true;
                    }
                }
            }

            // 3. Capturar filtros opcionales de búsqueda
            $filtros = [];
            if (!empty($_GET["id_estado"])) {
                $filtros["id_estado"] = (int)$_GET["id_estado"];
            }
            if (!empty($_GET["tipo_radicado"])) {
                $filtros["tipo_radicado"] = trim($_GET["tipo_radicado"]);
            }
            if (!empty($_GET["fecha"])) {
                $filtros["fecha"] = trim($_GET["fecha"]);
            }

            // 4. Invocar modelo de persistencia con sentencias preparadas
            $radicados = ModeloRadicados::mdlListarRadicados($idUsuario, $esAdmin, $filtros);

            RespuestaHelper::exito($radicados, "Listado de radicados obtenido correctamente");
        } catch (Exception $e) {
            error_log("Error en ControladorRadicados::ctrListar: " . $e->getMessage());
            RespuestaHelper::error("Error al obtener la lista de radicados.", 500);
        }
    }

    /**
     * Consulta y retorna el detalle de un radicado específico.
     */
    public static function ctrObtener(int $id): void
    {
        try {
            $radicado = ModeloRadicados::mdlObtenerRadicadoPorId($id);
            if (!$radicado) {
                RespuestaHelper::error("El radicado solicitado no existe.", 404);
                return;
            }
            RespuestaHelper::exito($radicado, "Detalle del radicado obtenido correctamente");
        } catch (Exception $e) {
            error_log("Error en ControladorRadicados::ctrObtener: " . $e->getMessage());
            RespuestaHelper::error("Error al obtener la información del radicado.", 500);
        }
    }

    /**
     * Endpoint REST: Obtiene el encargado del área asignado a una dependencia.
     */
    public static function ctrObtenerResponsableDependencia(int $idDependencia): void
    {
        try {
            $encargado = ModeloRadicados::mdlObtenerEncargadoDependencia($idDependencia);
            if ($encargado) {
                RespuestaHelper::exito($encargado, "Encargado de la dependencia obtenido exitosamente");
            } else {
                RespuestaHelper::error("No se encontró ningún encargado para esta dependencia", 404);
            }
        } catch (Exception $e) {
            error_log("Error en ControladorRadicados::ctrObtenerResponsableDependencia: " . $e->getMessage());
            RespuestaHelper::error("Error al consultar el encargado de la dependencia", 500);
        }
    }

    public static function ctrCatalogos(): void
    {
        try {
            $catalogos = ModeloRadicados::mdlObtenerCatalogos();
            RespuestaHelper::exito($catalogos, "Catálogos de radicación obtenidos correctamente");
        } catch (Exception $e) {
            error_log("Error en ControladorRadicados::ctrCatalogos: " . $e->getMessage());
            RespuestaHelper::error("Error al cargar los catálogos del sistema.", 500);
        }
    }

    /**
     * Endpoint REST: Consulta un remitente externo por número de documento para autollenado de formulario.
     */
    public static function ctrBuscarRemitente(string $numIdentificacion, ?string $tipoDoc = null): void
    {
        $numero = trim($numIdentificacion);
        if (empty($numero)) {
            RespuestaHelper::error("El número de documento es obligatorio para la búsqueda.", 400);
            return;
        }

        try {
            $remitente = ModeloRadicados::mdlBuscarRemitentePorDocumento($numero, $tipoDoc);
            if ($remitente) {
                RespuestaHelper::exito($remitente, "Remitente externo encontrado");
            } else {
                RespuestaHelper::error("No se encontró ningún remitente registrado con el número de documento proporcionado.", 404);
            }
        } catch (Exception $e) {
            error_log("Error en ControladorRadicados::ctrBuscarRemitente: " . $e->getMessage());
            RespuestaHelper::error("Error al consultar el remitente externo en el sistema.", 500);
        }
    }

    /**
     * Normaliza la estructura multidimensional o simple de $_FILES a una lista homogénea.
     */
    private static function normalizarArchivosSubidos(array $files): array
    {
        $normalizados = [];

        // Buscar campos comunes: 'adjuntos', 'adjuntos[]', 'archivo', 'documentos'
        foreach ($files as $clave => $item) {
            if (!isset($item["name"])) {
                continue;
            }

            if (is_array($item["name"])) {
                $total = count($item["name"]);
                for ($i = 0; $i < $total; $i++) {
                    if ($item["error"][$i] === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    $normalizados[] = [
                        "name"     => $item["name"][$i],
                        "type"     => $item["type"][$i],
                        "tmp_name" => $item["tmp_name"][$i],
                        "error"    => $item["error"][$i],
                        "size"     => $item["size"][$i]
                    ];
                }
            } else {
                if ($item["error"] !== UPLOAD_ERR_NO_FILE) {
                    $normalizados[] = $item;
                }
            }
        }

        return $normalizados;
    }

    /**
     * Limpia del almacenamiento los archivos procesados en caso de fallo posterior en BD.
     */
    private static function limpiarArchivosTemporales(array $rutasRelativas): void
    {
        foreach ($rutasRelativas as $ruta) {
            ArchivoServicio::revertirArchivo($ruta);
        }
    }

    /**
     * Calcula la fecha límite legal de respuesta en días hábiles según el trámite seleccionado,
     * cumpliendo con el Acuerdo 060/2001 del AGN y la Ley 1755 de 2015 (CPACA).
     */
    private static function calcularFechaLimitePorTramite(int $idTramite): ?string
    {
        try {
            $db = Conexion::conectar();
            $stmt = $db->prepare("SELECT nombre, dias_respuesta FROM tramites WHERE id_tramite = :id LIMIT 1");
            $stmt->bindParam(":id", $idTramite, PDO::PARAM_INT);
            $stmt->execute();
            $tramite = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$tramite) {
                return self::calcularFechaDiasHabilesColombia(15);
            }

            $nombre = mb_strtolower((string)($tramite["nombre"] ?? ""), "UTF-8");
            $diasBD = isset($tramite["dias_respuesta"]) ? (int)$tramite["dias_respuesta"] : 15;

            if (str_contains($nombre, "mero trámite") || str_contains($nombre, "mero tramite") || str_contains($nombre, "informativa")) {
                return null;
            }
            if (str_contains($nombre, "incompleta") || str_contains($nombre, "subsanación") || str_contains($nombre, "subsanacion") || str_contains($nombre, "desistimiento")) {
                $f = new DateTime();
                $f->modify("+1 month");
                return $f->format("Y-m-d");
            }
            if (str_contains($nombre, "tutela")) {
                return self::calcularFechaDiasHabilesColombia(2);
            }
            if (str_contains($nombre, "riesgo inminente") || str_contains($nombre, "prioritaria")) {
                return self::calcularFechaDiasHabilesColombia(0);
            }

            $dias = $diasBD > 0 ? $diasBD : 15;
            return self::calcularFechaDiasHabilesColombia($dias);
        } catch (Exception $e) {
            error_log("Error en ControladorRadicados::calcularFechaLimitePorTramite: " . $e->getMessage());
            return self::calcularFechaDiasHabilesColombia(15);
        }
    }

    /**
     * Suma días hábiles en Colombia a partir del primer día hábil siguiente a la radicación
     * (excluyendo sábados, domingos y festivos oficiales de la Ley 51 de 1983).
     */
    private static function calcularFechaDiasHabilesColombia(int $diasHabiles): string
    {
        $cursor = new DateTime();
        $cacheFestivos = [];

        $esDiaHabil = function (DateTime $fecha) use (&$cacheFestivos): bool {
            $dow = (int)$fecha->format("N"); // 1 (Lun) a 7 (Dom)
            if ($dow >= 6) {
                return false;
            }
            $anio = (int)$fecha->format("Y");
            if (!isset($cacheFestivos[$anio])) {
                $cacheFestivos[$anio] = self::obtenerFestivosColombia($anio);
            }
            return !in_array($fecha->format("Y-m-d"), $cacheFestivos[$anio], true);
        };

        if ($diasHabiles <= 0) {
            while (!$esDiaHabil($cursor)) {
                $cursor->modify("+1 day");
            }
            return $cursor->format("Y-m-d");
        }

        $restantes = $diasHabiles;
        while ($restantes > 0) {
            $cursor->modify("+1 day");
            if ($esDiaHabil($cursor)) {
                $restantes--;
            }
        }

        return $cursor->format("Y-m-d");
    }

    /**
     * Retorna el listado de fechas festivas en Colombia (YYYY-MM-DD) para un año dado.
     */
    private static function obtenerFestivosColombia(int $anio): array
    {
        $festivos = [
            "{$anio}-01-01",
            "{$anio}-05-01",
            "{$anio}-07-20",
            "{$anio}-08-07",
            "{$anio}-12-08",
            "{$anio}-12-25"
        ];

        $trasladarLunes = function (int $mes, int $dia) use ($anio): string {
            $f = new DateTime(sprintf("%04d-%02d-%02d", $anio, $mes, $dia));
            $dow = (int)$f->format("N");
            if ($dow !== 1) {
                $delta = 8 - $dow;
                $f->modify("+{$delta} days");
            }
            return $f->format("Y-m-d");
        };

        foreach ([[1, 6], [3, 19], [6, 29], [8, 15], [10, 12], [11, 1], [11, 11]] as [$m, $d]) {
            $festivos[] = $trasladarLunes($m, $d);
        }

        // Cálculo de Pascua (Algoritmo de Butcher/Meeus)
        $a = $anio % 19;
        $b = intdiv($anio, 100);
        $c = $anio % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mesPascua = intdiv($h + $l - 7 * $m + 114, 31);
        $diaPascua = (($h + $l - 7 * $m + 114) % 31) + 1;

        $pascua = new DateTime(sprintf("%04d-%02d-%02d", $anio, $mesPascua, $diaPascua));

        $sumarPascua = function (int $dias, bool $moverALunes) use ($pascua): string {
            $f = clone $pascua;
            $mod = $dias >= 0 ? "+{$dias} days" : "{$dias} days";
            $f->modify($mod);
            if ($moverALunes) {
                $dow = (int)$f->format("N");
                if ($dow !== 1) {
                    $delta = 8 - $dow;
                    $f->modify("+{$delta} days");
                }
            }
            return $f->format("Y-m-d");
        };

        $festivos[] = $sumarPascua(-3, false); // Jueves Santo
        $festivos[] = $sumarPascua(-2, false); // Viernes Santo
        $festivos[] = $sumarPascua(39, true);  // Ascensión del Señor
        $festivos[] = $sumarPascua(60, true);  // Corpus Christi
        $festivos[] = $sumarPascua(68, true);  // Sagrado Corazón de Jesús

        return array_values(array_unique($festivos));
    }
}
