<!-- Content Header (título de la página) -->
<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-6">
        <h1><i class="fas fa-file-alt mr-2"></i>Radicación Documental</h1>
        <p class="text-muted mb-0">Registro y asignación inicial del trámite de entrada</p>
      </div>
      <div class="col-sm-6">
        <ol class="breadcrumb float-sm-right">
          <li class="breadcrumb-item"><a href="inicio">Inicio</a></li>
          <li class="breadcrumb-item active">Radicación Documental</li>
        </ol>
      </div>
    </div>
  </div>
</section>

<!-- Main content -->
<section class="content">
  <div class="container-fluid">
    <form id="formRadicacion" name="formRadicacion" method="POST" enctype="multipart/form-data" class="form-horizontal">
      <div class="row">

        <!-- COLUMNA IZQUIERDA: Formulario -->
        <div class="col-md-8">

          <!-- Card: Información del Radicado -->
          <div class="card card-outline card-success">
            <div class="card-header">
              <h3 class="card-title">Información del Radicado</h3>
            </div>
            <div class="card-body">
              <div class="row">
                <div class="col-md-6 form-group">
                  <label for="numeroRadicado"><i class="fas fa-barcode mr-1"></i>Número de radicado</label>
                  <input type="text" class="form-control" id="numeroRadicado" name="numeroRadicado" placeholder="Se generará automáticamente" disabled>
                  <small class="form-text text-muted">Este número se asignará automáticamente al radicación</small>
                </div>
                <div class="col-md-6 form-group">
                  <label for="fechaHora"><i class="fas fa-calendar-alt mr-1"></i>Fecha y hora</label>
                  <input type="datetime-local" class="form-control" id="fechaHora" name="fechaHora" placeholder="Se asignará al radicar" disabled>
                </div>
              </div>

              <div class="row">
                <div class="col-md-6 form-group">
                  <label for="canalRecepcion"><i class="fas fa-inbox mr-1"></i>Canal de recepción <span class="text-danger">*</span></label>
                  <select class="form-control" id="canalRecepcion" name="canalRecepcion" required>
                    <option value="">Seleccione...</option>
                    <option value="presencial">Presencial</option>
                    <option value="correo_electronico">Correo electrónico</option>
                    <option value="plataforma_web">Plataforma web</option>
                    <option value="correo_fisico">Correo físico</option>
                  </select>
                  <div class="invalid-feedback">Este campo es requerido</div>
                </div>
                <div class="col-md-6 form-group">
                  <label for="tipoRadicacionVisible"><i class="fas fa-sign-in-alt mr-1"></i>Tipo de radicación</label>
                  <input type="text" class="form-control bg-light" id="tipoRadicacionVisible" value="Entrada" readonly>
                  <input type="hidden" id="tipoRadicacion" name="tipoRadicacion" value="entrada">
                  <small class="form-text text-muted">Establecido por defecto como documento de entrada</small>
                </div>
              </div>

              <div class="row">
                <div class="col-md-6 form-group">
                  <label for="tramite"><i class="fas fa-tasks mr-1"></i>Trámite <span class="text-danger">*</span></label>
                  <select class="form-control" id="tramite" name="tramite" required>
                    <option value="">Seleccione un trámite...</option>
                  </select>
                  <div class="invalid-feedback">Este campo es requerido</div>
                </div>
                <div class="col-md-6 form-group">
                  <label for="estadoInicialVisible"><i class="fas fa-flag mr-1"></i>Estado inicial</label>
                  <input type="text" class="form-control bg-light" id="estadoInicialVisible" value="Pendiente" readonly>
                  <input type="hidden" id="estadoInicial" name="estadoInicial" value="1">
                  <small class="form-text text-muted">Establecido por defecto como Pendiente</small>
                </div>
              </div>
            </div>
          </div>
          <!-- /Card Información del Radicado -->

          <!-- Card: Datos del Solicitante -->
          <div class="card card-outline card-success">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title"><i class="fas fa-user mr-1"></i>Datos del Solicitante</h3>
              <div class="card-tools ml-auto">
                <div class="custom-control custom-switch">
                  <input type="checkbox" class="custom-control-input" id="radicacionAnonima" name="radicacionAnonima" value="1">
                  <label class="custom-control-label font-weight-normal text-muted" for="radicacionAnonima">
                    <i class="fas fa-user-secret mr-1"></i>Radicación anónima (Ley de Transparencia - RF-09)
                  </label>
                </div>
              </div>
            </div>
            <div class="card-body" id="seccionDatosSolicitante">
              <div class="row">
                <div class="col-md-6 form-group">
                  <label for="tipoDocumento"><i class="fas fa-id-card mr-1"></i>Tipo de documento <span class="text-danger">*</span></label>
                  <select class="form-control" id="tipoDocumento" name="tipoDocumento" required>
                    <option value="">Seleccione...</option>
                    <option value="cc">Cédula de ciudadanía</option>
                    <option value="ce">Cédula de extranjería</option>
                    <option value="nit">NIT</option>
                    <option value="pasaporte">Pasaporte</option>
                  </select>
                  <div class="invalid-feedback">Este campo es requerido</div>
                </div>
                <div class="col-md-6 form-group">
                  <label for="numeroDocumento"><i class="fas fa-hashtag mr-1"></i>Número de documento <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" id="numeroDocumento" name="numeroDocumento" placeholder="Número de identificación" required autocomplete="off">
                  <div class="invalid-feedback">Este campo es requerido</div>
                  <small id="remitenteFeedback" class="form-text"></small>
                </div>
              </div>

              <div class="row">
                <div class="col-md-6 form-group">
                  <label for="nombreSolicitante"><i class="fas fa-user-circle mr-1"></i>Nombre completo / Razón social <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" id="nombreSolicitante" name="nombreSolicitante" placeholder="Nombre completo" required>
                  <div class="invalid-feedback">Este campo es requerido</div>
                </div>
                <div class="col-md-6 form-group">
                  <label for="correoSolicitante"><i class="fas fa-envelope mr-1"></i>Correo <span class="text-danger">*</span></label>
                  <input type="email" class="form-control" id="correoSolicitante" name="correoSolicitante" placeholder="correo@ejemplo.com" required>
                  <div class="invalid-feedback">Ingrese un correo válido</div>
                </div>
              </div>

              <div class="row">
                <div class="col-md-6 form-group">
                  <label for="telefonoSolicitante"><i class="fas fa-phone mr-1"></i>Teléfono <span class="text-danger">*</span></label>
                  <input type="tel" class="form-control" id="telefonoSolicitante" name="telefonoSolicitante" placeholder="Número de teléfono" required>
                  <div class="invalid-feedback">Este campo es requerido</div>
                </div>
                <div class="col-md-6 form-group">
                  <label for="ciudadSolicitante"><i class="fas fa-map-marker-alt mr-1"></i>Ciudad <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" id="ciudadSolicitante" name="ciudadSolicitante" placeholder="Ciudad" required>
                  <div class="invalid-feedback">Este campo es requerido</div>
                </div>
              </div>
            </div>
          </div>
          <!-- /Card Datos del Solicitante -->

          <!-- Card: Asignación Inicial -->
          <div class="card card-outline card-success">
            <div class="card-header">
              <h3 class="card-title"><i class="fas fa-user-check mr-1"></i>Asignación Inicial</h3>
            </div>
            <div class="card-body">
              <div class="row">
                <div class="col-md-6 form-group">
                  <label for="dependenciaResponsable"><i class="fas fa-building mr-1"></i>Dependencia asignada <span class="text-danger">*</span></label>
                  <select class="form-control" id="dependenciaResponsable" name="dependenciaResponsable" required>
                    <option value="">Seleccione una dependencia...</option>
                  </select>
                  <div class="invalid-feedback">Este campo es requerido</div>
                </div>
                <div class="col-md-6 form-group">
                  <label for="responsableVisible"><i class="fas fa-user-shield mr-1"></i>Encargado del área (Responsable)</label>
                  <input type="text" class="form-control bg-light" id="responsableVisible" placeholder="Se asignará automáticamente al seleccionar la dependencia" readonly>
                  <input type="hidden" id="responsable" name="responsable" value="">
                  <small class="form-text text-muted"><i class="fas fa-lock mr-1 text-secondary"></i>Asignado automáticamente al encargado del área (no editable)</small>
                </div>
              </div>

              <div class="row">
                <div class="col-md-6 form-group">
                  <label for="prioridad"><i class="fas fa-signal mr-1"></i>Prioridad <span class="text-danger">*</span></label>
                  <select class="form-control" id="prioridad" name="prioridad" required>
                    <option value="">Seleccione...</option>
                    <option value="alta">Alta</option>
                    <option value="media">Media</option>
                    <option value="baja">Baja</option>
                  </select>
                  <div class="invalid-feedback">Este campo es requerido</div>
                </div>
                <div class="col-md-6 form-group">
                  <label for="fechaLimiteVisible"><i class="fas fa-hourglass-end mr-1"></i>Fecha límite de respuesta</label>
                  <input type="text" class="form-control bg-light" id="fechaLimiteVisible" placeholder="Se calculará automáticamente al seleccionar el trámite" readonly>
                  <input type="hidden" id="fechaLimite" name="fechaLimite" value="">
                  <small id="terminoLegalInfo" class="form-text text-muted"><i class="fas fa-lock mr-1 text-secondary"></i>Calculada automáticamente en días hábiles según Acuerdo 060/2001 AGN y Ley 1755/2015 (no editable)</small>
                </div>
              </div>
            </div>
          </div>
          <!-- /Card Asignación Inicial -->

          <!-- Card: Información del Documento -->
          <div class="card card-outline card-success">
            <div class="card-header">
              <h3 class="card-title"><i class="fas fa-info-circle mr-1"></i>Información del Documento</h3>
            </div>
            <div class="card-body">
              <div class="row">
                <div class="col-md-12 form-group">
                  <label for="asuntoDocumento"><i class="fas fa-heading mr-1"></i>Asunto <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" id="asuntoDocumento" name="asuntoDocumento" placeholder="Asunto del documento" maxlength="200" required>
                  <small class="form-text text-muted">Máximo 200 caracteres</small>
                  <div class="invalid-feedback">Este campo es requerido</div>
                </div>
              </div>

              <div class="row">
                <div class="col-md-12 form-group">
                  <label for="descripcionTramite"><i class="fas fa-align-left mr-1"></i>Descripción del trámite <span class="text-danger">*</span></label>
                  <textarea class="form-control" id="descripcionTramite" name="descripcionTramite" rows="4" placeholder="Descripción detallada del trámite" required></textarea>
                  <div class="invalid-feedback">Este campo es requerido</div>
                </div>
              </div>

              <div class="row">
                <div class="col-md-6 form-group">
                  <label for="numeroFolios"><i class="fas fa-file-invoice mr-1"></i>Número de folios <span class="text-danger">*</span></label>
                  <input type="number" class="form-control" id="numeroFolios" name="numeroFolios" placeholder="Número de folios" min="1" required>
                  <div class="invalid-feedback">Este campo es requerido y debe ser mayor a 0</div>
                </div>
                <div class="col-md-6 form-group">
                  <label for="soporte"><i class="fas fa-compact-disc mr-1"></i>Soporte <span class="text-danger">*</span></label>
                  <select class="form-control" id="soporte" name="soporte" required>
                    <option value="">Seleccione...</option>
                    <option value="fisico">Físico</option>
                    <option value="digital">Digital</option>
                    <option value="mixto">Mixto</option>
                  </select>
                  <div class="invalid-feedback">Este campo es requerido</div>
                </div>
              </div>

              <div class="row">
                <div class="col-md-6 form-group">
                  <label for="fechaDocumento"><i class="fas fa-calendar mr-1"></i>Fecha del documento <span class="text-danger">*</span></label>
                  <input type="date" class="form-control" id="fechaDocumento" name="fechaDocumento" required>
                  <div class="invalid-feedback">Este campo es requerido</div>
                </div>
                <div class="col-md-6 form-group">
                  <label for="etiquetas"><i class="fas fa-tags mr-1"></i>Etiquetas</label>
                  <input type="text" class="form-control" id="etiquetas" name="etiquetas" placeholder="Separadas por comas">
                  <small class="form-text text-muted">Ej: urgente, seguimiento, importante</small>
                </div>
              </div>
            </div>
          </div>
          <!-- /Card Información del Documento -->

          <!-- Card: Adjuntos -->
          <div class="card card-outline card-success">
            <div class="card-header">
              <h3 class="card-title"><i class="fas fa-paperclip mr-1"></i>Adjuntos</h3>
            </div>
            <div class="card-body">
              <div class="form-group">
                <div class="border border-dashed border-warning p-4 rounded text-center" style="cursor: pointer; transition: all 0.3s;" id="dropZone">
                  <div class="mb-2">
                    <i class="fas fa-cloud-upload-alt fa-3x text-warning"></i>
                  </div>
                  <p class="mb-0">
                    <a href="javascript:void(0);" class="text-primary font-weight-bold">Elegir archivos</a> o cargue archivos aquí
                  </p>
                  <div class="small text-muted mt-2">
                    <i class="fas fa-info-circle mr-1"></i>Formatos permitidos: PDF, XLSX, XLS, JPG, PNG (Máx. 10MB)
                  </div>
                </div>
                <input type="file" id="fileInput" name="adjuntos[]" multiple accept=".pdf,.xlsx,.xls,.jpg,.jpeg,.png" style="display:none;">
              </div>
              <div id="fileList" class="mt-3"></div>
              <input type="hidden" id="totalFiles" name="totalFiles" value="0">
            </div>
          </div>
          <!-- /Card Adjuntos -->

          <!-- Card: Seguimiento Inicial -->
          <div class="card card-outline card-success">
            <div class="card-header">
              <h3 class="card-title"><i class="fas fa-tasks mr-1"></i>Seguimiento Inicial</h3>
            </div>
            <div class="card-body">
              <div class="row">
                <div class="col text-center">
                  <button type="button" class="btn btn-sm btn-success" disabled>
                    <i class="fas fa-check mr-1"></i>Radicado
                  </button>
                </div>
                <div class="col text-center">
                  <button type="button" class="btn btn-sm btn-light" disabled>
                    <i class="fas fa-folder-open mr-1"></i>Clasificado
                  </button>
                </div>
                <div class="col text-center">
                  <button type="button" class="btn btn-sm btn-light" disabled>
                    <i class="fas fa-user-check mr-1"></i>Asignado
                  </button>
                </div>
                <div class="col text-center">
                  <button type="button" class="btn btn-sm btn-light" disabled>
                    <i class="fas fa-spinner mr-1"></i>En Gestión
                  </button>
                </div>
                <div class="col text-center">
                  <button type="button" class="btn btn-sm btn-light" disabled>
                    <i class="fas fa-flag-checkered mr-1"></i>Finalizado
                  </button>
                </div>
              </div>
            </div>
          </div>
          <!-- /Card Seguimiento Inicial -->

          <!-- Botones de acción -->
          <div class="row mt-3">
            <div class="col-md-12">
              <button type="reset" class="btn btn-secondary" onclick="limpiarFormulario()">
                <i class="fas fa-redo mr-1"></i>Limpiar
              </button>
              <button type="submit" class="btn btn-success float-right" id="btnRadicar">
                <i class="fas fa-save mr-1"></i>Radicación Documental
              </button>
            </div>
          </div>
          <!-- /Botones de acción -->

        </div>
        <!-- /COLUMNA IZQUIERDA -->

        <!-- COLUMNA DERECHA: Panel resumen -->
        <div class="col-md-4">

          <div class="card card-outline card-secondary">
            <div class="card-header">
              <h3 class="card-title"><i class="fas fa-list-alt mr-1"></i>Resumen</h3>
            </div>
            <div class="card-body p-0">
              <dl class="row px-3 pt-3 mb-0">
                <dt class="col-sm-5"><i class="fas fa-sign-in-alt mr-1"></i>Tipo:</dt>
                <dd class="col-sm-7"><span class="badge badge-info"><i class="fas fa-arrow-circle-down mr-1"></i>Entrada</span></dd>

                <dt class="col-sm-5"><i class="fas fa-user mr-1"></i>Solicitante:</dt>
                <dd class="col-sm-7"><span class="resumen-solicitante text-muted">—</span></dd>

                <dt class="col-sm-5"><i class="fas fa-building mr-1"></i>Dependencia:</dt>
                <dd class="col-sm-7"><span class="resumen-dependencia text-muted">—</span></dd>

                <dt class="col-sm-5"><i class="fas fa-user-shield mr-1"></i>Encargado:</dt>
                <dd class="col-sm-7"><span class="resumen-responsable text-muted">—</span></dd>

                <dt class="col-sm-5"><i class="fas fa-heading mr-1"></i>Asunto:</dt>
                <dd class="col-sm-7"><span class="resumen-asunto text-muted">—</span></dd>

                <dt class="col-sm-5"><i class="fas fa-paperclip mr-1"></i>Adjuntos:</dt>
                <dd class="col-sm-7"><span class="resumen-adjuntos text-muted">0</span></dd>
              </dl>
            </div>
          </div>

          <div class="card card-outline card-info">
            <div class="card-header">
              <h3 class="card-title"><i class="fas fa-flag mr-1"></i>Estado</h3>
            </div>
            <div class="card-body">
              <div class="text-center">
                <span class="badge badge-warning badge-lg px-3 py-2" id="badgeEstado">
                  <i class="fas fa-circle mr-2"></i>Pendiente
                </span>
              </div>
              <hr>
              <div class="small">
                <p class="mb-1"><strong><i class="fas fa-signal mr-1"></i>Prioridad:</strong> <span class="resumen-prioridad text-muted">—</span></p>
                <p class="mb-0"><strong><i class="fas fa-hourglass-end mr-1"></i>Fecha límite:</strong> <span class="resumen-fechaLimite text-muted">—</span></p>
              </div>
            </div>
          </div>

          <div class="card card-outline card-dark">
            <div class="card-header">
              <h3 class="card-title"><i class="fas fa-history mr-1"></i>Auditoría</h3>
            </div>
            <div class="card-body p-0">
              <dl class="row px-3 pt-3 mb-0">
                <dt class="col-sm-6"><i class="fas fa-barcode mr-1"></i>ID radicado:</dt>
                <dd class="col-sm-6"><span class="text-muted">—</span></dd>

                <dt class="col-sm-6"><i class="fas fa-calendar-alt mr-1"></i>Fecha:</dt>
                <dd class="col-sm-6"><span class="text-muted">—</span></dd>

                <dt class="col-sm-6"><i class="fas fa-user-circle mr-1"></i>Usuario:</dt>
                <dd class="col-sm-6"><span class="text-muted">Actual</span></dd>

                <dt class="col-sm-6"><i class="fas fa-code-branch mr-1"></i>Versión:</dt>
                <dd class="col-sm-6"><span class="text-muted">1.0</span></dd>
              </dl>
            </div>
          </div>

          <!-- Información de ayuda -->
          <div class="alert alert-info alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
            <h5><i class="icon fas fa-info mr-2"></i>Información</h5>
            Los campos marcados con <span class="text-danger">*</span> son obligatorios. Todos los datos serán validados antes de radicar.
          </div>

        </div>
        <!-- /COLUMNA DERECHA -->

      </div>
    </form>
  </div>
</section>

<!-- Vinculación del script del módulo -->
<script src="vistas/js/radicacion.js"></script>