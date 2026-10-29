<?php

require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/seguimiento.modelo.php";

/**
 * Modelo de Persistencia para Radicados y Documentos — Proyecto ODIN
 * 
 * Gestiona la persistencia atómica mediante PDO con sentencias preparadas,
 * generación concurrente de consecutivos por vigencia anual y registro
 * de metadatos documentales y de trazabilidad.
 */
class ModeloRadicados
{
    /**
     * Genera de forma atómica y concurrente el siguiente número de radicado
     * respetando la vigencia anual y el prefijo según el tipo de radicación:
     * - ENTRADA -> E-AAAA-000001
     * - SALIDA  -> S-AAAA-000001
     * - INTERNO -> INT-AAAA-000001
     */
    public static function mdlGenerarNumeroRadicado(string $tipoRadicado, ?PDO $pdo = null): string
    {
        $db = $pdo ?? Conexion::conectar();
        $anio = (int)date("Y");

        $tipoNormalizado = strtoupper(trim($tipoRadicado));
        switch ($tipoNormalizado) {
            case "SALIDA":
                $prefijo = "S";
                break;
            case "INTERNO":
            case "INTERNA":
                $prefijo = "INT";
                break;
            case "ENTRADA":
            default:
                $prefijo = "E";
                break;
        }

        // Bloqueo y actualización atómica en radicado_consecutivos
        $stmtUpdate = $db->prepare(
            "INSERT INTO radicado_consecutivos (anio, prefijo, consecutivo)
             VALUES (:anio, :prefijo, 1)
             ON DUPLICATE KEY UPDATE consecutivo = consecutivo + 1"
        );
        $stmtUpdate->bindParam(":anio", $anio, PDO::PARAM_INT);
        $stmtUpdate->bindParam(":prefijo", $prefijo, PDO::PARAM_STR);
        $stmtUpdate->execute();

        $stmtSelect = $db->prepare(
            "SELECT consecutivo FROM radicado_consecutivos
             WHERE anio = :anio AND prefijo = :prefijo"
        );
        $stmtSelect->bindParam(":anio", $anio, PDO::PARAM_INT);
        $stmtSelect->bindParam(":prefijo", $prefijo, PDO::PARAM_STR);
        $stmtSelect->execute();
        $fila = $stmtSelect->fetch(PDO::FETCH_ASSOC);

        $consecutivo = $fila ? (int)$fila["consecutivo"] : 1;
        $consecutivoPad = str_pad((string)$consecutivo, 6, "0", STR_PAD_LEFT);

        return "{$prefijo}-{$anio}-{$consecutivoPad}";
    }

    /**
     * Persiste de forma atómica un radicado, sus datos de remitente (si aplica),
     * sus documentos adjuntos procesados y el registro de bitácora/historial.
     */
    public static function mdlRadicarDocumento(
        array $datosRadicado,
        array $documentosMetadatos,
        ?array $datosRemitente = null
    ): array {
        $db = Conexion::conectar();
        $db->beginTransaction();

        try {
            // 1. Manejo del Remitente Externo (si no es anónimo)
            $idRemitente = null;
            $esAnonimo = !empty($datosRadicado["es_anonimo"]);
            $remitenteTexto = $esAnonimo ? "ANÓNIMO (Ley de Transparencia)" : trim($datosRadicado["remitente"] ?? "Anónimo");

            if (!$esAnonimo && $datosRemitente && !empty($datosRemitente["num_identificacion"])) {
                $tipoDoc = strtoupper(trim($datosRemitente["tipo_identificacion"] ?? "CC"));
                $numDoc  = trim($datosRemitente["num_identificacion"]);

                $stmtBuscaRem = $db->prepare(
                    "SELECT id_remitente FROM remitentes_externos
                     WHERE tipo_identificacion = :tipo AND num_identificacion = :num"
                );
                $stmtBuscaRem->bindParam(":tipo", $tipoDoc, PDO::PARAM_STR);
                $stmtBuscaRem->bindParam(":num",  $numDoc,  PDO::PARAM_STR);
                $stmtBuscaRem->execute();
                $remExistente = $stmtBuscaRem->fetch(PDO::FETCH_ASSOC);

                if ($remExistente) {
                    $idRemitente = (int)$remExistente["id_remitente"];
                } else {
                    $stmtInsRem = $db->prepare(
                        "INSERT INTO remitentes_externos
                         (tipo_identificacion, num_identificacion, nombre, apellido, correo, telefono, direccion)
                         VALUES (:tipo, :num, :nom, :ape, :correo, :tel, :dir)"
                    );
                    $nombreRem = trim($datosRemitente["nombre"] ?? "");
                    $apellidoRem = trim($datosRemitente["apellido"] ?? "");
                    $correoRem = trim($datosRemitente["correo"] ?? "");
                    $telRem = trim($datosRemitente["telefono"] ?? "");
                    $dirRem = trim($datosRemitente["direccion"] ?? "");

                    $stmtInsRem->bindParam(":tipo",   $tipoDoc,     PDO::PARAM_STR);
                    $stmtInsRem->bindParam(":num",    $numDoc,      PDO::PARAM_STR);
                    $stmtInsRem->bindParam(":nom",    $nombreRem,   PDO::PARAM_STR);
                    $stmtInsRem->bindParam(":ape",    $apellidoRem, PDO::PARAM_STR);
                    $stmtInsRem->bindParam(":correo", $correoRem,   PDO::PARAM_STR);
                    $stmtInsRem->bindParam(":tel",    $telRem,      PDO::PARAM_STR);
                    $stmtInsRem->bindParam(":dir",    $dirRem,      PDO::PARAM_STR);
                    $stmtInsRem->execute();
                    $idRemitente = (int)$db->lastInsertId();
                }

                if (!empty($datosRemitente["nombre_completo"])) {
                    $remitenteTexto = trim($datosRemitente["nombre_completo"]);
                }
            }

            // 2. Generación del número de radicado con consecutivo anual
            $tipoRadicado = strtoupper(trim($datosRadicado["tipo_radicado"] ?? "ENTRADA"));
            if (!in_array($tipoRadicado, ["ENTRADA", "SALIDA", "INTERNO"], true)) {
                $tipoRadicado = "ENTRADA";
            }

            $numeroRadicado = self::mdlGenerarNumeroRadicado($tipoRadicado, $db);

            // 3. Normalizar parámetros para las restricciones CHECK de BD
            $prioridad = strtoupper(trim($datosRadicado["prioridad"] ?? "MEDIA"));
            if (!in_array($prioridad, ["BAJA", "MEDIA", "ALTA", "URGENTE"], true)) {
                $prioridad = "MEDIA";
            }

            $confidencialidad = strtoupper(trim($datosRadicado["confidencialidad"] ?? "PUBLICO"));
            if (!in_array($confidencialidad, ["PUBLICO", "RESERVADO", "CONFIDENCIAL"], true)) {
                $confidencialidad = "PUBLICO";
            }

            $medioRecepcion = trim($datosRadicado["medio_recepcion"] ?? "Ventanilla");
            $asunto = trim($datosRadicado["asunto"] ?? "");
            $fechaRadicado = date("Y-m-d H:i:s");
            $fechaLimite = !empty($datosRadicado["fecha_limite"]) ? trim($datosRadicado["fecha_limite"]) : null;
            $fechaVencimiento = !empty($datosRadicado["fecha_vencimiento"]) ? trim($datosRadicado["fecha_vencimiento"]) : $fechaLimite;

            $idTramite     = !empty($datosRadicado["id_tramite"]) ? (int)$datosRadicado["id_tramite"] : null;
            $idEstado      = !empty($datosRadicado["id_estado"]) ? (int)$datosRadicado["id_estado"] : 1; // 1 = Pendiente
            $idDependencia = !empty($datosRadicado["id_dependencia"]) ? (int)$datosRadicado["id_dependencia"] : null;
            $idUsuario     = !empty($datosRadicado["id_usuario"]) ? (int)$datosRadicado["id_usuario"] : 1;

            $codigoSerie    = !empty($datosRadicado["codigo_serie"]) ? trim($datosRadicado["codigo_serie"]) : null;
            $codigoSubserie = !empty($datosRadicado["codigo_subserie"]) ? trim($datosRadicado["codigo_subserie"]) : null;
            $idSerie        = !empty($datosRadicado["id_serie"]) ? (int)$datosRadicado["id_serie"] : null;
            $idSubserie     = !empty($datosRadicado["id_subserie"]) ? (int)$datosRadicado["id_subserie"] : null;

            // 4. Inserción en tabla radicados
            $sqlRadicado = "INSERT INTO radicados (
                numero_radicado, id_tramite, id_estado, id_dependencia, id_usuario,
                id_remitente, remitente, asunto, fecha_radicado, tipo_radicado,
                medio_recepcion, prioridad, fecha_vencimiento, confidencialidad, fecha_limite,
                codigo_serie, codigo_subserie, id_serie, id_subserie
            ) VALUES (
                :num_rad, :tramite, :estado, :dep, :usuario,
                :rem_id, :rem_txt, :asunto, :f_rad, :tipo,
                :medio, :prio, :f_venc, :conf, :f_lim,
                :cod_serie, :cod_subserie, :id_serie, :id_subserie
            )";

            $stmtRad = $db->prepare($sqlRadicado);
            $stmtRad->bindParam(":num_rad", $numeroRadicado,  PDO::PARAM_STR);
            $stmtRad->bindValue(":tramite", $idTramite,        $idTramite ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmtRad->bindParam(":estado",  $idEstado,        PDO::PARAM_INT);
            $stmtRad->bindValue(":dep",     $idDependencia,    $idDependencia ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmtRad->bindParam(":usuario", $idUsuario,        PDO::PARAM_INT);
            $stmtRad->bindValue(":rem_id",  $idRemitente,      $idRemitente ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmtRad->bindParam(":rem_txt", $remitenteTexto,  PDO::PARAM_STR);
            $stmtRad->bindParam(":asunto",  $asunto,          PDO::PARAM_STR);
            $stmtRad->bindParam(":f_rad",   $fechaRadicado,   PDO::PARAM_STR);
            $stmtRad->bindParam(":tipo",    $tipoRadicado,    PDO::PARAM_STR);
            $stmtRad->bindParam(":medio",   $medioRecepcion,  PDO::PARAM_STR);
            $stmtRad->bindParam(":prio",    $prioridad,       PDO::PARAM_STR);
            $stmtRad->bindValue(":f_venc",  $fechaVencimiento,$fechaVencimiento ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmtRad->bindParam(":conf",    $confidencialidad,PDO::PARAM_STR);
            $stmtRad->bindValue(":f_lim",   $fechaLimite,     $fechaLimite ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmtRad->bindValue(":cod_serie",    $codigoSerie,    $codigoSerie ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmtRad->bindValue(":cod_subserie", $codigoSubserie, $codigoSubserie ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmtRad->bindValue(":id_serie",     $idSerie,        $idSerie ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmtRad->bindValue(":id_subserie",  $idSubserie,     $idSubserie ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmtRad->execute();

            $idRadicado = (int)$db->lastInsertId();

            // 5. Inserción de documentos asociados
            $docsInsertados = [];
            $sqlDoc = "INSERT INTO documentos (
                id_radicado, tamano, nombre_archivo, ruta_archivo, tipo,
                fecha_subida, nombre, mime_type, checksum, version_actual
            ) VALUES (
                :rad_id, :tamano, :nom_arch, :ruta, :tipo,
                :f_sub, :nom_orig, :mime, :hash, 1
            )";
            $stmtDoc = $db->prepare($sqlDoc);

            foreach ($documentosMetadatos as $docMeta) {
                $fechaSubida = date("Y-m-d H:i:s");
                $stmtDoc->bindParam(":rad_id",   $idRadicado,                PDO::PARAM_INT);
                $stmtDoc->bindParam(":tamano",   $docMeta["tamano"],         PDO::PARAM_INT);
                $stmtDoc->bindParam(":nom_arch", $docMeta["nombre_archivo"],  PDO::PARAM_STR);
                $stmtDoc->bindParam(":ruta",     $docMeta["ruta_archivo"],    PDO::PARAM_STR);
                $stmtDoc->bindParam(":tipo",     $docMeta["tipo"],            PDO::PARAM_STR);
                $stmtDoc->bindParam(":f_sub",    $fechaSubida,               PDO::PARAM_STR);
                $stmtDoc->bindParam(":nom_orig", $docMeta["nombre_original"], PDO::PARAM_STR);
                $stmtDoc->bindParam(":mime",     $docMeta["mime_type"],      PDO::PARAM_STR);
                $stmtDoc->bindParam(":hash",     $docMeta["checksum"],       PDO::PARAM_STR);
                $stmtDoc->execute();

                $docMeta["id_documento"] = (int)$db->lastInsertId();
                $docsInsertados[] = $docMeta;
            }

            // 6. Registro de Trazabilidad e Historial inicial
            $sqlHistorial = "INSERT INTO historial_radicado (
                id_radicado, id_usuario, accion, descripcion, fecha
            ) VALUES (
                :rad_id, :usuario, 'radicacion', :desc, :fecha
            )";
            $descHistorial = "Radicación inicial generada exitosamente (" . count($docsInsertados) . " documento(s) adjunto(s)).";
            $stmtHist = $db->prepare($sqlHistorial);
            $stmtHist->bindParam(":rad_id",  $idRadicado,     PDO::PARAM_INT);
            $stmtHist->bindParam(":usuario", $idUsuario,       PDO::PARAM_INT);
            $stmtHist->bindParam(":desc",    $descHistorial,   PDO::PARAM_STR);
            $stmtHist->bindParam(":fecha",   $fechaRadicado,   PDO::PARAM_STR);
            $stmtHist->execute();

            $db->commit();

            return [
                "id_radicado"     => $idRadicado,
                "numero_radicado" => $numeroRadicado,
                "tipo_radicado"   => $tipoRadicado,
                "remitente"       => $remitenteTexto,
                "asunto"          => $asunto,
                "fecha_radicado"  => $fechaRadicado,
                "documentos"      => $docsInsertados
            ];
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Error en ModeloRadicados::mdlRadicarDocumento: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Consulta el listado de radicados con detalles relacionados para DataTables y seguimiento.
     * Si el usuario es Administrador o no se especifica usuario, retorna todos los radicados.
     * Si es un usuario regular, filtra exclusivamente los radicados asociados a su id_usuario.
     * 
     * @param int|null $idUsuario Identificador del usuario en sesión.
     * @param bool $esAdmin Bandera de rol administrador (true = ve todos los radicados).
     * @param array $filtros Filtros adicionales opcionales (id_estado, tipo_radicado, fecha).
     * @return array Listado de radicados con relaciones.
     */
    public static function mdlListarRadicados(?int $idUsuario = null, bool $esAdmin = false, array $filtros = []): array
    {
        try {
            $db = Conexion::conectar();
            $sql = "SELECT r.id_radicado, r.numero_radicado, r.remitente, r.asunto,
                           r.tipo_radicado, r.prioridad, r.confidencialidad, r.medio_recepcion,
                           r.fecha_radicado, r.fecha_limite, r.fecha_vencimiento,
                           r.id_usuario,
                           COALESCE(u.nombre, 'Sin asignar') AS responsable,
                           u.correo AS responsable_correo,
                           e.id_estado,
                           COALESCE(e.nombre, 'Sin estado') AS estado,
                           COALESCE(e.color, '#6c757d') AS estado_color,
                           d.id_dependencia,
                           d.nombre AS dependencia,
                           t.id_tramite,
                           t.nombre AS tramite,
                           (SELECT COUNT(*) FROM documentos d2 WHERE d2.id_radicado = r.id_radicado) AS total_documentos,
                           (SELECT d3.ruta_archivo FROM documentos d3 WHERE d3.id_radicado = r.id_radicado ORDER BY d3.id_documento ASC LIMIT 1) AS ruta_documento,
                           (SELECT d3.nombre_archivo FROM documentos d3 WHERE d3.id_radicado = r.id_radicado ORDER BY d3.id_documento ASC LIMIT 1) AS nombre_archivo,
                           (SELECT d3.id_documento FROM documentos d3 WHERE d3.id_radicado = r.id_radicado ORDER BY d3.id_documento ASC LIMIT 1) AS id_documento
                    FROM radicados r
                    LEFT JOIN usuarios u ON u.id_usuario = r.id_usuario
                    LEFT JOIN estados e ON e.id_estado = r.id_estado
                    LEFT JOIN dependencias d ON d.id_dependencia = r.id_dependencia
                    LEFT JOIN tramites t ON t.id_tramite = r.id_tramite";

            $condiciones = [];
            $params = [];

            // Regla de negocio: Si NO es administrador y se cuenta con idUsuario, filtrar por dicho usuario
            if (!$esAdmin && $idUsuario !== null) {
                $condiciones[] = "r.id_usuario = :id_usuario";
                $params[":id_usuario"] = ["valor" => $idUsuario, "tipo" => PDO::PARAM_INT];
            }

            // Filtros complementarios opcionales
            if (!empty($filtros["id_estado"])) {
                $condiciones[] = "r.id_estado = :id_estado";
                $params[":id_estado"] = ["valor" => (int)$filtros["id_estado"], "tipo" => PDO::PARAM_INT];
            }

            if (!empty($filtros["tipo_radicado"])) {
                $condiciones[] = "r.tipo_radicado = :tipo_radicado";
                $params[":tipo_radicado"] = ["valor" => trim($filtros["tipo_radicado"]), "tipo" => PDO::PARAM_STR];
            }

            if (!empty($filtros["fecha"])) {
                $condiciones[] = "DATE(r.fecha_radicado) = :fecha";
                $params[":fecha"] = ["valor" => trim($filtros["fecha"]), "tipo" => PDO::PARAM_STR];
            }

            if (!empty($condiciones)) {
                $sql .= " WHERE " . implode(" AND ", $condiciones);
            }

            $sql .= " ORDER BY r.id_radicado DESC";

            $stmt = $db->prepare($sql);
            foreach ($params as $paramClave => $paramItem) {
                $stmt->bindValue($paramClave, $paramItem["valor"], $paramItem["tipo"]);
            }

            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error en ModeloRadicados::mdlListarRadicados: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Consulta el detalle de un radicado específico por ID con sus documentos.
     */
    public static function mdlObtenerRadicadoPorId(int $idRadicado): ?array
    {
        $stmt = Conexion::conectar()->prepare(
            "SELECT r.*, e.nombre AS estado_nombre, e.color AS estado_color,
                    d.nombre AS dependencia_nombre, t.nombre AS tramite_nombre
             FROM radicados r
             LEFT JOIN estados e ON e.id_estado = r.id_estado
             LEFT JOIN dependencias d ON d.id_dependencia = r.id_dependencia
             LEFT JOIN tramites t ON t.id_tramite = r.id_tramite
             WHERE r.id_radicado = :id"
        );
        $stmt->bindParam(":id", $idRadicado, PDO::PARAM_INT);
        $stmt->execute();
        $radicado = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$radicado) {
            return null;
        }

        $stmtDocs = Conexion::conectar()->prepare(
            "SELECT id_documento, nombre, nombre_archivo, ruta_archivo, tipo,
                    tamano, mime_type, checksum, fecha_subida
             FROM documentos
             WHERE id_radicado = :id ORDER BY id_documento ASC"
        );
        $stmtDocs->bindParam(":id", $idRadicado, PDO::PARAM_INT);
        $stmtDocs->execute();
        $radicado["documentos"] = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);

        return $radicado;
    }

    /**
     * Obtiene los catálogos necesarios para alimentar el formulario de radicación.
     */
    public static function mdlObtenerCatalogos(): array
    {
        $db = Conexion::conectar();

        $stmtTramites = $db->prepare(
            "SELECT t.id_tramite, t.nombre, t.descripcion, t.dias_respuesta, t.prioridad_default,
                    t.id_dependencia_responsable,
                    u.id_usuario AS id_usuario_responsable,
                    u.nombre AS nombre_responsable
             FROM tramites t
             LEFT JOIN usuarios u ON u.id_usuario = (
                 SELECT u2.id_usuario
                 FROM usuarios u2
                 WHERE u2.id_dependencia = t.id_dependencia_responsable AND u2.estado = 'Activo'
                 ORDER BY u2.id_rol ASC, u2.id_usuario ASC
                 LIMIT 1
             )
             WHERE t.activo = 1 ORDER BY t.nombre ASC"
        );
        $stmtTramites->execute();
        $tramites = $stmtTramites->fetchAll(PDO::FETCH_ASSOC);

        $stmtDeps = $db->prepare(
            "SELECT d.id_dependencia, d.nombre, d.codigo, d.tipo,
                    u.id_usuario AS id_responsable,
                    u.nombre AS nombre_responsable,
                    u.correo AS correo_responsable,
                    COALESCE(r.nombre, 'Encargado del área') AS rol_responsable
             FROM dependencias d
             LEFT JOIN usuarios u ON u.id_usuario = (
                 SELECT u2.id_usuario
                 FROM usuarios u2
                 WHERE u2.id_dependencia = d.id_dependencia AND u2.estado = 'Activo'
                 ORDER BY u2.id_rol ASC, u2.id_usuario ASC
                 LIMIT 1
             )
             LEFT JOIN roles r ON r.id_rol = u.id_rol
             WHERE d.estado IN ('Activo', 'Activa')
             ORDER BY d.nombre ASC"
        );
        $stmtDeps->execute();
        $dependencias = $stmtDeps->fetchAll(PDO::FETCH_ASSOC);

        $stmtEstados = $db->prepare(
            "SELECT id_estado, nombre, color FROM estados ORDER BY id_estado ASC"
        );
        $stmtEstados->execute();
        $estados = $stmtEstados->fetchAll(PDO::FETCH_ASSOC);

        $stmtSeries = $db->prepare(
            "SELECT id_serie, codigo_serie, nombre_serie, codigo_unidad, descripcion
             FROM ccd_series ORDER BY codigo_serie ASC"
        );
        $stmtSeries->execute();
        $series = $stmtSeries->fetchAll(PDO::FETCH_ASSOC);

        $stmtSubseries = $db->prepare(
            "SELECT id_subserie, codigo_subserie, nombre_subserie, id_serie, retencion_anios, disposicion_final
             FROM ccd_subseries ORDER BY codigo_subserie ASC"
        );
        $stmtSubseries->execute();
        $subseries = $stmtSubseries->fetchAll(PDO::FETCH_ASSOC);

        return [
            "tramites"     => $tramites,
            "dependencias" => $dependencias,
            "estados"      => $estados,
            "series"       => $series,
            "subseries"    => $subseries
        ];
    }

    /**
     * Obtiene el usuario encargado del área (activo) asignado a una dependencia.
     * Retorna los datos del usuario o null si no existe.
     *
     * @param int $idDependencia ID de la dependencia.
     * @return array|null Datos del encargado o null.
     */
    public static function mdlObtenerEncargadoDependencia(int $idDependencia): ?array
    {
        try {
            $db = Conexion::conectar();
            $stmt = $db->prepare(
                "SELECT u.id_usuario, u.nombre, u.correo, u.id_dependencia,
                        COALESCE(r.nombre, 'Encargado del área') AS rol
                 FROM usuarios u
                 LEFT JOIN roles r ON r.id_rol = u.id_rol
                 WHERE u.id_dependencia = :id_dep AND u.estado = 'Activo'
                 ORDER BY u.id_rol ASC, u.id_usuario ASC
                 LIMIT 1"
            );
            $stmt->bindParam(":id_dep", $idDependencia, PDO::PARAM_INT);
            $stmt->execute();
            $encargado = $stmt->fetch(PDO::FETCH_ASSOC);

            return $encargado ?: null;
        } catch (Exception $e) {
            error_log("Error en ModeloRadicados::mdlObtenerEncargadoDependencia: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Consulta un remitente externo por número de identificación y opcionalmente tipo.
     * Retorna datos estructurados para autollenado o null si no se encuentra.
     *
     * @param string $numIdentificacion Número de documento a consultar.
     * @param string|null $tipoDoc Tipo de identificación opcional (CC, CE, NIT, etc.).
     * @return array|null Datos del remitente o null si no existe.
     */
    public static function mdlBuscarRemitentePorDocumento(string $numIdentificacion, ?string $tipoDoc = null): ?array
    {
        $db = Conexion::conectar();
        $numDoc = trim($numIdentificacion);

        if (!empty($tipoDoc)) {
            $tipo = strtoupper(trim($tipoDoc));
            $stmt = $db->prepare(
                "SELECT id_remitente, tipo_identificacion, num_identificacion, nombre, apellido, correo, telefono, direccion
                 FROM remitentes_externos
                 WHERE num_identificacion = :num AND UPPER(tipo_identificacion) = :tipo
                 LIMIT 1"
            );
            $stmt->bindParam(":num",  $numDoc, PDO::PARAM_STR);
            $stmt->bindParam(":tipo", $tipo,   PDO::PARAM_STR);
        } else {
            $stmt = $db->prepare(
                "SELECT id_remitente, tipo_identificacion, num_identificacion, nombre, apellido, correo, telefono, direccion
                 FROM remitentes_externos
                 WHERE num_identificacion = :num
                 ORDER BY id_remitente DESC
                 LIMIT 1"
            );
            $stmt->bindParam(":num", $numDoc, PDO::PARAM_STR);
        }

        $stmt->execute();
        $remitente = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$remitente) {
            return null;
        }

        $nombreCompleto = trim($remitente["nombre"] . " " . ($remitente["apellido"] ?? ""));
        $remitente["nombre_completo"] = !empty($nombreCompleto) ? $nombreCompleto : $remitente["nombre"];

        return $remitente;
    }
}
