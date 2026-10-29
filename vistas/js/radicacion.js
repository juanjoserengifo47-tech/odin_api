/**
 * Lógica Cliente del Módulo de Radicación Documental — Proyecto ODIN
 * 
 * Gestiona la carga asíncrona de catálogos vía API RESTful, carga reactiva de archivos,
 * validación de tipos MIME y tamaño, radicación anónima y notificaciones SweetAlert2.
 */

const API_RADICADOS_URL = "api/radicados";
const API_CATALOGOS_URL = "api/radicados/catalogos";
const API_USUARIOS_URL   = "api/usuarios";
const API_REMITENTES_URL = "api/remitentes";

// Formatos permitidos en cliente
const ALLOWED_MIME_TYPES = [
    "application/pdf",
    "application/vnd.ms-excel",
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    "image/jpeg",
    "image/png"
];
const MAX_FILE_SIZE_BYTES = 15 * 1024 * 1024; // 15MB

let uploadedFiles = [];

$(document).ready(function() {
    inicializarCatalogos();
    inicializarEventosArchivos();
    inicializarRadicacionAnonima();
    inicializarAutollenadoRemitente();
    inicializarEventosResumen();
    inicializarEnvioFormulario();

    // Establecer fecha de hoy como valor por defecto en fechaDocumento
    const hoy = new Date().toISOString().split("T")[0];
    const fechaDocInput = document.getElementById("fechaDocumento");
    if (fechaDocInput && !fechaDocInput.value) {
        fechaDocInput.value = hoy;
    }
});

/**
 * Carga de catálogos maestros desde la API REST (trámites, dependencias, estados, usuarios)
 */
function inicializarCatalogos() {
    // 1. Cargar Trámites, Dependencias y Estados
    fetch(API_CATALOGOS_URL)
        .then(r => r.json())
        .then(res => {
            if (res.success && res.data) {
                poblarSelectTramites(res.data.tramites || []);
                poblarSelectDependencias(res.data.dependencias || []);
                poblarSelectEstados(res.data.estados || []);
            }
        })
        .catch(err => {
            console.error("Error al cargar catálogos maestros:", err);
        });
}

function poblarSelectTramites(tramites) {
    const $select = $("#tramite");
    $select.empty().append('<option value="">Seleccione un trámite...</option>');
    tramites.forEach(t => {
        $select.append(`<option value="${t.id_tramite}" 
                                data-dias="${t.dias_respuesta !== undefined && t.dias_respuesta !== null ? t.dias_respuesta : 15}" 
                                data-prio="${t.prioridad_default || 'MEDIA'}"
                                data-dep="${t.id_dependencia_responsable || ''}"
                                data-nombre="${t.nombre || ''}">${t.nombre}</option>`);
    });

    $select.on("change", function() {
        const val = $(this).val();
        if (!val) {
            limpiarFechaLimiteAutomatica();
            actualizarResumen();
            return;
        }

        const selectedOpt = $(this).find("option:selected");
        const prioridadDef = selectedOpt.data("prio");
        if (prioridadDef) {
            $("#prioridad").val(prioridadDef.toLowerCase()).trigger("change");
        }

        // Asignación por defecto de la dependencia y responsable del trámite
        const depId = selectedOpt.data("dep");
        if (depId) {
            $("#dependenciaResponsable").val(depId);
            asignarEncargadoPorDependencia(depId);
        }

        // Cálculo automático e inmutable de la Fecha Límite según Acuerdo 060/2001 AGN y Ley 1755/2015
        const diasBase = parseInt(selectedOpt.data("dias"), 10);
        const nombreTramite = selectedOpt.data("nombre") || selectedOpt.text();
        asignarFechaLimitePorTramite(nombreTramite, isNaN(diasBase) ? 15 : diasBase);
    });
}

/**
 * Determina el término legal y fundamento normativo según la Guía de Términos Legales
 * de Trámite y Respuesta Documental (Acuerdo 060/2001 AGN • Ley 1755/2015 CPACA).
 */
function obtenerNormativaTramite(nombreTramite, diasBaseBD) {
    const texto = (nombreTramite || "").toLowerCase();

    if (texto.includes("tutela")) {
        return {
            diasHabiles: 2,
            etiquetaTermino: "24 a 48 horas (2 días hábiles)",
            norma: "Decreto 2591 de 1991 (Término perentorio e improrrogable)"
        };
    }
    if (texto.includes("riesgo inminente") || texto.includes("prioritaria")) {
        return {
            diasHabiles: 0,
            etiquetaTermino: "Trámite inmediato",
            norma: "Art. 20, Ley 1755 de 2015"
        };
    }
    if (texto.includes("mero trámite") || texto.includes("mero tramite") || texto.includes("informativa")) {
        return {
            diasHabiles: null,
            etiquetaTermino: "Sin término (archivo)",
            norma: "Acuerdo 060 de 2001 AGN (Gestión de archivo)"
        };
    }
    if (texto.includes("incompleta") || texto.includes("subsanación") || texto.includes("subsanacion") || texto.includes("desistimiento")) {
        return {
            diasHabiles: 30,
            esMesCalendario: true,
            etiquetaTermino: "1 mes calendario",
            norma: "Art. 17, Ley 1755 de 2015 (Prorrogable por 1 mes)"
        };
    }
    if (texto.includes("consulta") && (texto.includes("técnica") || texto.includes("tecnica") || texto.includes("jurídica") || texto.includes("juridica") || texto.includes("doctrinal"))) {
        return {
            diasHabiles: 30,
            etiquetaTermino: "30 días hábiles",
            norma: "Art. 14 num. 3, Ley 1755 de 2015"
        };
    }
    if (texto.includes("congresista") || texto.includes("comisiones") || texto.includes("control político") || texto.includes("control politico")) {
        return {
            diasHabiles: 5,
            etiquetaTermino: "5 días hábiles",
            norma: "Art. 258, Ley 5 de 1992 (Reglamento Congreso)"
        };
    }
    if (texto.includes("incompetencia") || texto.includes("interna") || texto.includes("traslado")) {
        const dias = diasBaseBD > 0 ? diasBaseBD : 5;
        return {
            diasHabiles: dias,
            etiquetaTermino: `${dias} días hábiles`,
            norma: "Art. 21, Ley 1755 de 2015; Acuerdo AGN 060 de 2001"
        };
    }
    if (texto.includes("judicial") || texto.includes("juzgado")) {
        const dias = diasBaseBD > 0 ? diasBaseBD : 10;
        return {
            diasHabiles: dias,
            etiquetaTermino: `${dias} días hábiles (5 a 10 días hábiles)`,
            norma: "Ley 1564 de 2012 (Código General del Proceso)"
        };
    }
    if (texto.includes("órgano") || texto.includes("organo") || texto.includes("pgn") || texto.includes("cgr") || texto.includes("defensoría") || texto.includes("defensoria")) {
        const dias = diasBaseBD > 0 ? diasBaseBD : 10;
        return {
            diasHabiles: dias,
            etiquetaTermino: `${dias} días hábiles (5 a 10 días hábiles)`,
            norma: "Ley 1952 de 2019, Ley 610 de 2000, Ley 24 de 1992"
        };
    }
    if (texto.includes("recurso") || texto.includes("reposición") || texto.includes("reposicion") || texto.includes("apelación") || texto.includes("apelacion")) {
        return {
            diasHabiles: 15,
            etiquetaTermino: "15 días hábiles",
            norma: "Art. 86 CPACA (salvo norma especial de 2 meses)"
        };
    }
    if (texto.includes("oscura") || texto.includes("confusa") || texto.includes("aclaración") || texto.includes("aclaracion")) {
        return {
            diasHabiles: 10,
            etiquetaTermino: "10 días hábiles",
            norma: "Art. 19, Ley 1755 de 2015"
        };
    }
    if (texto.includes("autoridades") || texto.includes("entidades")) {
        return {
            diasHabiles: 10,
            etiquetaTermino: "10 días hábiles",
            norma: "Art. 30, Ley 1755 de 2015"
        };
    }
    if (texto.includes("información") || texto.includes("informacion") || texto.includes("copia") || texto.includes("certificaci")) {
        const dias = diasBaseBD > 0 ? diasBaseBD : 10;
        return {
            diasHabiles: dias,
            etiquetaTermino: `${dias} días hábiles`,
            norma: "Art. 14, Ley 1755 de 2015; Acuerdo AGN 060 de 2001"
        };
    }
    if (texto.includes("queja") || texto.includes("reclamo") || texto.includes("pqr")) {
        const dias = diasBaseBD > 0 ? diasBaseBD : 15;
        return {
            diasHabiles: dias,
            etiquetaTermino: `${dias} días hábiles`,
            norma: "Ley 1755 de 2015; Circular Externa 001 del DAFP"
        };
    }
    if (texto.includes("petición") || texto.includes("peticion")) {
        const dias = diasBaseBD > 0 ? diasBaseBD : 15;
        return {
            diasHabiles: dias,
            etiquetaTermino: `${dias} días hábiles`,
            norma: "Art. 14, Ley 1755 de 2015; Art. 13 Ley 1437 de 2011"
        };
    }

    const dias = diasBaseBD > 0 ? diasBaseBD : 15;
    return {
        diasHabiles: dias,
        etiquetaTermino: `${dias} días hábiles`,
        norma: "Acuerdo 060 de 2001 AGN; Ley 1755 de 2015 (CPACA)"
    };
}

/**
 * Calcula los festivos oficiales de Colombia para un año dado (Ley 51 de 1983 - Ley Emiliani)
 */
function obtenerFestivosColombia(anio) {
    const festivos = new Set();
    const fmt = (fecha) => {
        const y = fecha.getFullYear();
        const m = String(fecha.getMonth() + 1).padStart(2, "0");
        const d = String(fecha.getDate()).padStart(2, "0");
        return `${y}-${m}-${d}`;
    };
    const trasladarLunes = (mes, dia) => {
        const f = new Date(anio, mes - 1, dia);
        const dow = f.getDay(); // 0 = Dom, 1 = Lun ... 6 = Sab
        if (dow !== 1) {
            const delta = dow === 0 ? 1 : (8 - dow);
            f.setDate(f.getDate() + delta);
        }
        return fmt(f);
    };

    // 1. Festivos de fecha fija inamovible
    ["01-01", "05-01", "07-20", "08-07", "12-08", "12-25"].forEach(md => {
        festivos.add(`${anio}-${md}`);
    });

    // 2. Festivos trasladables al lunes siguiente (Ley Emiliani)
    [
        [1, 6],   // Reyes Magos
        [3, 19],  // San José
        [6, 29],  // San Pedro y San Pablo
        [8, 15],  // Asunción de la Virgen
        [10, 12], // Día de la Raza
        [11, 1],  // Todos los Santos
        [11, 11]  // Independencia de Cartagena
    ].forEach(([m, d]) => festivos.add(trasladarLunes(m, d)));

    // 3. Festivos móviles respecto al Domingo de Pascua (Algoritmo de Butcher/Meeus)
    const a = anio % 19;
    const b = Math.floor(anio / 100);
    const c = anio % 100;
    const d = Math.floor(b / 4);
    const e = b % 4;
    const f = Math.floor((b + 8) / 25);
    const g = Math.floor((b - f + 1) / 3);
    const h = (19 * a + b - d - g + 15) % 30;
    const i = Math.floor(c / 4);
    const k = c % 4;
    const l = (32 + 2 * e + 2 * i - h - k) % 7;
    const m = Math.floor((a + 11 * h + 22 * l) / 451);
    const mesPascua = Math.floor((h + l - 7 * m + 114) / 31);
    const diaPascua = ((h + l - 7 * m + 114) % 31) + 1;

    const sumarDiasPascua = (dias, moverALunes) => {
        const fecha = new Date(anio, mesPascua - 1, diaPascua);
        fecha.setDate(fecha.getDate() + dias);
        if (moverALunes) {
            const dow = fecha.getDay();
            if (dow !== 1) {
                const delta = dow === 0 ? 1 : (8 - dow);
                fecha.setDate(fecha.getDate() + delta);
            }
        }
        return fmt(fecha);
    };

    festivos.add(sumarDiasPascua(-3, false)); // Jueves Santo
    festivos.add(sumarDiasPascua(-2, false)); // Viernes Santo
    festivos.add(sumarDiasPascua(39, true));  // Ascensión del Señor
    festivos.add(sumarDiasPascua(60, true));  // Corpus Christi
    festivos.add(sumarDiasPascua(68, true));  // Sagrado Corazón de Jesús

    return festivos;
}

/**
 * Calcula la fecha límite sumando días hábiles a partir del día hábil siguiente a la radicación
 * (Directriz Técnica 2 del Acuerdo 060/2001 AGN y Ley 1755 de 2015: no incluye sábados, domingos ni festivos).
 */
function calcularFechaLimiteDiasHabiles(diasHabiles, fechaBase = new Date()) {
    const cursor = new Date(fechaBase.getFullYear(), fechaBase.getMonth(), fechaBase.getDate());
    const cacheFestivos = {};

    const esDiaHabil = (fecha) => {
        const dow = fecha.getDay();
        if (dow === 0 || dow === 6) return false; // Domingo o Sábado
        const y = fecha.getFullYear();
        if (!cacheFestivos[y]) {
            cacheFestivos[y] = obtenerFestivosColombia(y);
        }
        const m = String(fecha.getMonth() + 1).padStart(2, "0");
        const d = String(fecha.getDate()).padStart(2, "0");
        return !cacheFestivos[y].has(`${y}-${m}-${d}`);
    };

    // Trámite inmediato (0 días): vence el mismo día hábil o el primer día hábil disponible
    if (diasHabiles === 0) {
        while (!esDiaHabil(cursor)) {
            cursor.setDate(cursor.getDate() + 1);
        }
    } else {
        let restantes = diasHabiles;
        while (restantes > 0) {
            cursor.setDate(cursor.getDate() + 1);
            if (esDiaHabil(cursor)) {
                restantes--;
            }
        }
    }

    const yyyy = cursor.getFullYear();
    const mm = String(cursor.getMonth() + 1).padStart(2, "0");
    const dd = String(cursor.getDate()).padStart(2, "0");
    return `${yyyy}-${mm}-${dd}`;
}

function asignarFechaLimitePorTramite(nombreTramite, diasBaseBD) {
    const info = obtenerNormativaTramite(nombreTramite, diasBaseBD);
    const $inputHidden = $("#fechaLimite");
    const $inputVisible = $("#fechaLimiteVisible");
    const $infoNorma = $("#terminoLegalInfo");

    if (info.diasHabiles === null) {
        $inputHidden.val("");
        $inputVisible.val("Sin término legal (Archivo / Mero trámite)");
        $infoNorma.html(`<i class="fas fa-balance-scale mr-1 text-info"></i><strong>${info.etiquetaTermino}:</strong> ${info.norma}`);
        actualizarResumen();
        return;
    }

    let fechaCalculada = "";
    if (info.esMesCalendario) {
        const f = new Date();
        f.setMonth(f.getMonth() + 1);
        const yyyy = f.getFullYear();
        const mm = String(f.getMonth() + 1).padStart(2, "0");
        const dd = String(f.getDate()).padStart(2, "0");
        fechaCalculada = `${yyyy}-${mm}-${dd}`;
    } else {
        fechaCalculada = calcularFechaLimiteDiasHabiles(info.diasHabiles, new Date());
    }

    const [anio, mes, dia] = fechaCalculada.split("-");
    const fechaFormateada = `${dia}/${mes}/${anio} (${info.etiquetaTermino})`;

    $inputHidden.val(fechaCalculada);
    $inputVisible.val(fechaFormateada);
    $infoNorma.html(
        `<i class="fas fa-balance-scale mr-1 text-success"></i><strong>${info.etiquetaTermino}:</strong> ${info.norma} <span class="text-muted">(Cómputo desde el día hábil siguiente - No editable)</span>`
    );

    $inputVisible.addClass("border-success text-dark font-weight-bold");
    setTimeout(() => {
        $inputVisible.removeClass("border-success text-dark font-weight-bold");
    }, 2000);

    actualizarResumen();
}

function limpiarFechaLimiteAutomatica() {
    $("#fechaLimite").val("");
    $("#fechaLimiteVisible").val("");
    $("#terminoLegalInfo").html(
        '<i class="fas fa-lock mr-1 text-secondary"></i>Calculada automáticamente en días hábiles según Acuerdo 060/2001 AGN y Ley 1755/2015 (no editable)'
    );
}

let catalogoDependencias = [];

function poblarSelectDependencias(dependencias) {
    catalogoDependencias = dependencias || [];
    const $resp = $("#dependenciaResponsable");

    $resp.empty().append('<option value="">Seleccione una dependencia...</option>');

    catalogoDependencias.forEach(d => {
        const optionHtml = `<option value="${d.id_dependencia}">[${d.codigo || 'DEP'}] ${d.nombre}</option>`;
        $resp.append(optionHtml);
    });

    // Al seleccionar dependencia se autollena el responsable encargado y no permite editarlo
    $("#dependenciaResponsable").off("change.encargado").on("change.encargado", function() {
        const depId = $(this).val();

        if (!depId) {
            $("#responsable").val("");
            $("#responsableVisible").val("");
            actualizarResumen();
            return;
        }

        asignarEncargadoPorDependencia(depId);
    });
}

function asignarEncargadoPorDependencia(idDependencia) {
    const dep = catalogoDependencias.find(d => String(d.id_dependencia) === String(idDependencia));

    if (dep && dep.id_responsable && dep.nombre_responsable) {
        const rolTexto = dep.rol_responsable ? ` (${dep.rol_responsable})` : "";
        $("#responsable").val(dep.id_responsable);
        $("#responsableVisible").val(`${dep.nombre_responsable}${rolTexto}`);
        resaltarResponsableAutollenado();
        actualizarResumen();
        return;
    }

    // Consulta complementaria a la API RESTful
    fetch(`api/dependencias/${idDependencia}/responsable`)
        .then(r => r.json())
        .then(res => {
            if (res.success && res.data) {
                const u = res.data;
                const rolTexto = u.rol ? ` (${u.rol})` : "";
                $("#responsable").val(u.id_usuario);
                $("#responsableVisible").val(`${u.nombre}${rolTexto}`);
                resaltarResponsableAutollenado();
            } else {
                $("#responsable").val("");
                $("#responsableVisible").val("Sin encargado asignado");
            }
            actualizarResumen();
        })
        .catch(() => {
            $("#responsable").val("");
            $("#responsableVisible").val("Sin encargado asignado");
            actualizarResumen();
        });
}

function resaltarResponsableAutollenado() {
    const $respVis = $("#responsableVisible");
    $respVis.addClass("border-success text-dark font-weight-bold");
    setTimeout(() => {
        $respVis.removeClass("border-success text-dark font-weight-bold");
    }, 2000);
}

function poblarSelectEstados(estados) {
    // Fijar el estado inicial "Pendiente" por defecto sin permitir modificación
    const estadoPendiente = estados.find(e => e.nombre && e.nombre.toLowerCase().includes("pendiente"));
    if (estadoPendiente) {
        $("#estadoInicial").val(estadoPendiente.id_estado);
        $("#estadoInicialVisible").val(estadoPendiente.nombre);
    } else {
        $("#estadoInicial").val("1");
        $("#estadoInicialVisible").val("Pendiente");
    }
}


/**
 * Gestión interactiva de arrastrar y soltar (Drag & Drop) y selección de archivos
 */
function inicializarEventosArchivos() {
    const dropZone = document.getElementById("dropZone");
    const fileInput = document.getElementById("fileInput");

    if (!dropZone || !fileInput) return;

    dropZone.addEventListener("click", () => fileInput.click());

    dropZone.addEventListener("dragover", (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropZone.style.backgroundColor = "#e8f5e9";
        dropZone.style.borderColor = "#28a745";
    });

    dropZone.addEventListener("dragleave", (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropZone.style.backgroundColor = "transparent";
        dropZone.style.borderColor = "#ffc107";
    });

    dropZone.addEventListener("drop", (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropZone.style.backgroundColor = "transparent";
        dropZone.style.borderColor = "#ffc107";
        agregarArchivos(e.dataTransfer.files);
    });

    fileInput.addEventListener("change", (e) => {
        agregarArchivos(e.target.files);
        fileInput.value = ""; // Permitir volver a seleccionar el mismo archivo si fue eliminado
    });
}

function agregarArchivos(archivosFileList) {
    const rechazados = [];

    for (let i = 0; i < archivosFileList.length; i++) {
        const file = archivosFileList[i];

        // Validar tipo MIME
        if (!ALLOWED_MIME_TYPES.includes(file.type)) {
            rechazados.push(`${file.name}: Formato no permitido. Solo PDF, Excel (XLSX, XLS), JPG y PNG.`);
            continue;
        }

        // Validar peso máximo
        if (file.size > MAX_FILE_SIZE_BYTES) {
            rechazados.push(`${file.name}: Supera el límite de 15MB.`);
            continue;
        }

        // Evitar duplicados por nombre y tamaño
        const yaExiste = uploadedFiles.some(f => f.name === file.name && f.size === file.size);
        if (!yaExiste) {
            uploadedFiles.push(file);
        }
    }

    if (rechazados.length > 0) {
        Swal.fire({
            icon: "warning",
            title: "Archivos no admitidos",
            html: '<ul class="text-left">' + rechazados.map(r => `<li>${r}</li>`).join('') + '</ul>'
        });
    }

    renderizarListaArchivos();
    actualizarResumen();
}

function eliminarArchivo(index) {
    uploadedFiles.splice(index, 1);
    renderizarListaArchivos();
    actualizarResumen();
}

function renderizarListaArchivos() {
    const $container = $("#fileList");
    const $totalFiles = $("#totalFiles");
    $totalFiles.val(uploadedFiles.length);

    if (uploadedFiles.length === 0) {
        $container.html('<p class="text-muted text-center"><i class="fas fa-inbox mr-2"></i>Ningún archivo adjuntado</p>');
        return;
    }

    let html = `
    <div class="alert alert-success py-2 mb-2">
        <i class="fas fa-check-circle mr-1"></i><strong>${uploadedFiles.length}</strong> archivo(s) listo(s) para radicar
    </div>`;

    uploadedFiles.forEach((file, idx) => {
        const tamanoKB = (file.size / 1024).toFixed(1);
        const iconClass = obtenerIconoPorMime(file.type);

        html += `
        <div class="alert alert-light border border-left-success py-2 px-3 mb-2 d-flex justify-content-between align-items-center">
            <div class="text-truncate mr-2">
                <i class="fas ${iconClass} mr-2 text-success"></i>
                <strong>${file.name}</strong>
                <span class="text-muted small ml-2">(${tamanoKB} KB)</span>
            </div>
            <button type="button" class="btn btn-outline-danger btn-xs" onclick="eliminarArchivo(${idx})" title="Quitar archivo">
                <i class="fas fa-trash-alt"></i>
            </button>
        </div>`;
    });

    $container.html(html);
}

function obtenerIconoPorMime(mime) {
    if (mime === "application/pdf") return "fa-file-pdf text-danger";
    if (mime.includes("excel") || mime.includes("spreadsheet")) return "fa-file-excel text-success";
    if (mime.startsWith("image/")) return "fa-file-image text-info";
    return "fa-file-alt text-secondary";
}

/**
 * Control del Switch de Radicación Anónima (Ley de Transparencia RF-09)
 */

let debounceTimerRemitente = null;
let ultimoDocumentoBuscado = "";

/**
 * Autollenado reactivo de datos del solicitante consultando la tabla remitentes_externos
 */
function inicializarAutollenadoRemitente() {
    const $numDoc = $("#numeroDocumento");
    if (!$numDoc.length) return;

    // 1. Detección en tiempo real con debounce al escribir o pegar
    $numDoc.on("input", function() {
        const valor = $(this).val().trim();
        clearTimeout(debounceTimerRemitente);

        if (valor.length < 3) {
            $("#remitenteFeedback").empty();
            ultimoDocumentoBuscado = "";
            return;
        }

        debounceTimerRemitente = setTimeout(() => {
            consultarYAutollenarRemitente(valor);
        }, 400);
    });

    // 2. Consulta inmediata al perder el foco (blur) o cambio (change)
    $numDoc.on("blur change", function() {
        const valor = $(this).val().trim();
        if (valor.length >= 3 && valor !== ultimoDocumentoBuscado) {
            clearTimeout(debounceTimerRemitente);
            consultarYAutollenarRemitente(valor);
        }
    });

    // 3. Revalidar si cambia el tipo de documento con un número ya ingresado
    $("#tipoDocumento").on("change", function() {
        const valor = $numDoc.val().trim();
        if (valor.length >= 3) {
            ultimoDocumentoBuscado = "";
            consultarYAutollenarRemitente(valor);
        }
    });
}

/**
 * Consulta a la API RESTful los datos del remitente externo para autollenar el formulario
 */
function consultarYAutollenarRemitente(numeroDocumento) {
    if ($("#radicacionAnonima").is(":checked")) return;
    if (!numeroDocumento || numeroDocumento === ultimoDocumentoBuscado) return;

    const tipoDoc = $("#tipoDocumento").val();
    const queryParams = tipoDoc ? `?tipo=${encodeURIComponent(tipoDoc)}` : "";
    const url = `${API_REMITENTES_URL}/${encodeURIComponent(numeroDocumento)}${queryParams}`;

    fetch(url)
        .then(response => response.json().then(data => ({ status: response.status, body: data })))
        .then(({ status, body }) => {
            ultimoDocumentoBuscado = numeroDocumento;

            if (status === 200 && body.success && body.data) {
                const rem = body.data;
                const nombreCompleto = rem.nombre_completo || [rem.nombre, rem.apellido].filter(Boolean).join(" ");

                // Sincronizar select de tipo de documento
                if (rem.tipo_identificacion) {
                    const tipoNormalizado = rem.tipo_identificacion.toLowerCase();
                    if ($(`#tipoDocumento option[value="${tipoNormalizado}"]`).length) {
                        $("#tipoDocumento").val(tipoNormalizado);
                    }
                }

                // Autollenar campos del solicitante
                if (nombreCompleto) {
                    $("#nombreSolicitante").val(nombreCompleto);
                }
                if (rem.correo) {
                    $("#correoSolicitante").val(rem.correo);
                }
                if (rem.telefono) {
                    $("#telefonoSolicitante").val(rem.telefono);
                }
                if (rem.direccion) {
                    $("#ciudadSolicitante").val(rem.direccion);
                }

                // Mensaje informativo sutil
                $("#remitenteFeedback").html(
                    '<span class="text-success small font-weight-bold"><i class="fas fa-check-circle mr-1"></i>Remitente registrado: Datos cargados automáticamente.</span>'
                );

                // Destello visual en campos autocompletados
                const $camposLlenados = $("#nombreSolicitante, #correoSolicitante, #telefonoSolicitante, #ciudadSolicitante");
                $camposLlenados.addClass("border-success");
                setTimeout(() => $camposLlenados.removeClass("border-success"), 2000);

                actualizarResumen();
            } else {
                $("#remitenteFeedback").html(
                    '<span class="text-muted small"><i class="fas fa-user-plus mr-1"></i>Remitente nuevo: Complete los datos para su registro.</span>'
                );
            }
        })
        .catch(err => {
            console.warn("Aviso al consultar remitente externo:", err);
            $("#remitenteFeedback").empty();
        });
}
function inicializarRadicacionAnonima() {
    $("#radicacionAnonima").on("change", function() {
        const esAnonimo = $(this).is(":checked");
        const campos = [
            "#tipoDocumento",
            "#numeroDocumento",
            "#nombreSolicitante",
            "#correoSolicitante",
            "#telefonoSolicitante",
            "#ciudadSolicitante"
        ];

        if (esAnonimo) {
            campos.forEach(c => {
                const $c = $(c);
                $c.val("");
                $c.prop("disabled", true);
                $c.removeAttr("required");
                $c.removeClass("is-invalid is-valid");
            });
            ultimoDocumentoBuscado = "";
            $("#remitenteFeedback").empty();
            $("#seccionDatosSolicitante").addClass("bg-light text-muted");
            $(".resumen-solicitante").html('<span class="badge badge-warning"><i class="fas fa-user-secret mr-1"></i>ANÓNIMO (Ley de Transparencia)</span>');
        } else {
            campos.forEach(c => {
                const $c = $(c);
                $c.prop("disabled", false);
            });
            $("#tipoDocumento, #numeroDocumento, #nombreSolicitante").attr("required", "required");
            $("#seccionDatosSolicitante").removeClass("bg-light text-muted");
            actualizarResumen();
        }
    });
}

/**
 * Actualización reactiva del panel lateral de Resumen
 */
function inicializarEventosResumen() {
    const campos = [
        "#numeroDocumento",
        "#nombreSolicitante",
        "#dependenciaResponsable",
        "#asuntoDocumento",
        "#prioridad",
        "#fechaLimite"
    ];

    $(campos.join(",")).on("change keyup", actualizarResumen);
}

function actualizarResumen() {
    const esAnonimo = $("#radicacionAnonima").is(":checked");
    if (esAnonimo) {
        $(".resumen-solicitante").html('<span class="badge badge-warning"><i class="fas fa-user-secret mr-1"></i>ANÓNIMO (Ley de Transparencia)</span>');
    } else {
        const num = $("#numeroDocumento").val().trim();
        const nom = $("#nombreSolicitante").val().trim();
        const solTexto = (num || nom) ? `${num} - ${nom}` : "—";
        $(".resumen-solicitante").text(solTexto);
    }

    const depText = $("#dependenciaResponsable option:selected").text();
    $(".resumen-dependencia").text(depText && !depText.includes("Seleccione") ? depText : "—");

    const respText = $("#responsableVisible").val();
    $(".resumen-responsable").text(respText && !respText.includes("Se asignará") ? respText : "—");

    const asunto = $("#asuntoDocumento").val().trim();
    $(".resumen-asunto").text(asunto || "—");

    $(".resumen-adjuntos").text(uploadedFiles.length);

    const prio = $("#prioridad option:selected").text();
    $(".resumen-prioridad").text(prio && !prio.includes("Seleccione") ? prio : "—");

    const fechaLimVis = $("#fechaLimiteVisible").val() || $("#fechaLimite").val();
    $(".resumen-fechaLimite").text(fechaLimVis || "—");
}

/**
 * Limpieza integral del formulario
 */
function limpiarFormulario() {
    $("#formRadicacion")[0].reset();
    $("#formRadicacion").removeClass("was-validated");
    $("#tipoRadicacion").val("entrada");
    $("#tipoRadicacionVisible").val("Entrada");
    $("#estadoInicial").val("1");
    $("#estadoInicialVisible").val("Pendiente");
    ultimoDocumentoBuscado = "";
    $("#remitenteFeedback").empty();
    $("#dependenciaResponsable").val("");
    $("#responsable").val("");
    $("#responsableVisible").val("");
    limpiarFechaLimiteAutomatica();
    uploadedFiles = [];
    renderizarListaArchivos();
    $("#radicacionAnonima").prop("checked", false).trigger("change");
    actualizarResumen();
}

/**
 * Intercepción y envío del formulario a la API RESTful
 */
function inicializarEnvioFormulario() {
    const form = document.getElementById("formRadicacion");
    if (!form) return;

    form.addEventListener("submit", function(e) {
        e.preventDefault();

        // 1. Validar presencia de adjuntos obligatorios
        if (uploadedFiles.length === 0) {
            Swal.fire({
                icon: "warning",
                title: "Documento adjunto requerido",
                text: "Debe adjuntar al menos un archivo digital (PDF, Excel o imagen) para completar el proceso de radicación."
            });
            return;
        }

        // 2. Validación de HTML5
        if (!form.checkValidity()) {
            e.stopPropagation();
            form.classList.add("was-validated");
            Swal.fire({
                icon: "error",
                title: "Campos incompletos",
                text: "Por favor complete todos los campos obligatorios marcados con asterisco (*)."
            });
            return;
        }

        // 3. Preparar FormData para envío multipart
        const formData = new FormData(form);

        // Adjuntar los archivos del array uploadedFiles
        formData.delete("adjuntos[]");
        uploadedFiles.forEach(file => {
            formData.append("adjuntos[]", file);
        });

        // 4. Bloquear botón de envío con spinner
        const $btn = $("#btnRadicar");
        const btnHtmlOriginal = $btn.html();
        $btn.prop("disabled", true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Radicando documento...');

        // 5. Envío Asíncrono a la API RESTful
        fetch(API_RADICADOS_URL, {
            method: "POST",
            body: formData
        })
        .then(response => response.json().then(data => ({ status: response.status, body: data })))
        .then(({ status, body }) => {
            if (status === 201 && body.success) {
                const rad = body.data;
                const totalDocs = rad.documentos ? rad.documentos.length : 1;

                Swal.fire({
                    icon: "success",
                    title: "¡Radicación Exitosa!",
                    html: `
                        <div class="text-center my-3">
                            <span class="text-muted">Número oficial asignado:</span>
                            <h2 class="text-success font-weight-bold mt-1 mb-2">${rad.numero_radicado}</h2>
                            <p class="mb-1"><strong>Fecha de radicado:</strong> ${rad.fecha_radicado}</p>
                            <p class="mb-1"><strong>Tipo:</strong> <span class="badge badge-info">${rad.tipo_radicado}</span></p>
                            <p class="mb-0"><strong>Documentos almacenados:</strong> ${totalDocs}</p>
                        </div>
                    `,
                    confirmButtonText: '<i class="fas fa-check mr-1"></i> Aceptar',
                    confirmButtonColor: "#28a745"
                });

                limpiarFormulario();
            } else {
                Swal.fire({
                    icon: "error",
                    title: "Error al radicar",
                    text: body.message || "No fue posible procesar la radicación del documento."
                });
            }
        })
        .catch(err => {
            console.error("Fallo de conexión:", err);
            Swal.fire({
                icon: "error",
                title: "Error de conexión",
                text: "No fue posible establecer comunicación con el servidor. Verifique su red e intente nuevamente."
            });
        })
        .finally(() => {
            $btn.prop("disabled", false).html(btnHtmlOriginal);
        });
    });
}
