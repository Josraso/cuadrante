<?php require_once 'config.php'; ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cuadrante de Pulido - Gestión de Turnos</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>Cuadrante de Pulido</h1>
            <p>Sistema de gestión de turnos y asignación de puestos</p>
        </header>

        <!-- Pestañas de navegación -->
        <div class="tabs">
            <button class="tab-btn active" data-tab="cuadrante">Cuadrante</button>
            <button class="tab-btn" data-tab="estadisticas">Estadísticas</button>
            <button class="tab-btn" data-tab="generar">Generar Nuevo</button>
            <button class="tab-btn" data-tab="personas">Gestionar Personas</button>
        </div>

        <!-- PESTAÑA: Cuadrante -->
        <div id="tab-cuadrante" class="tab-content active">
            <div class="section-header">
                <h2>Cuadrantes Guardados</h2>
                <div class="actions">
                    <select id="select-cuadrante" class="select-field">
                        <option value="">Seleccionar cuadrante...</option>
                    </select>
                    <button id="btn-exportar-csv" class="btn btn-primary" style="display:none;">📊 Exportar CSV</button>
                    <button id="btn-imprimir" class="btn btn-secondary" style="display:none;">🖨️ Imprimir</button>
                    <button id="btn-eliminar-cuadrante" class="btn btn-danger" style="display:none;">🗑️ Eliminar</button>
                </div>
            </div>

            <!-- Filtros -->
            <div id="filtros-container" style="display:none; margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: center;">
                    <div style="flex: 1; min-width: 200px;">
                        <input type="text" id="buscar-persona" class="input-field" placeholder="Buscar por nombre de persona...">
                    </div>
                    <div>
                        <select id="filtro-turno" class="select-field">
                            <option value="">Todos los turnos</option>
                            <option value="mañana">Solo Mañanas</option>
                            <option value="tarde">Solo Tardes</option>
                        </select>
                    </div>
                    <div>
                        <button id="btn-limpiar-filtros" class="btn btn-secondary btn-small">Limpiar Filtros</button>
                    </div>
                </div>
            </div>

            <div id="cuadrante-display" class="cuadrante-container">
                <p class="info-message">Selecciona un cuadrante para visualizar</p>
            </div>
        </div>

        <!-- PESTAÑA: Estadísticas -->
        <div id="tab-estadisticas" class="tab-content">
            <div class="section-header">
                <h2>Estadísticas del Cuadrante</h2>
                <select id="select-cuadrante-stats" class="select-field">
                    <option value="">Seleccionar cuadrante...</option>
                </select>
            </div>

            <div id="estadisticas-display">
                <p class="info-message">Selecciona un cuadrante para ver las estadísticas</p>
            </div>
        </div>

        <!-- PESTAÑA: Generar Nuevo -->
        <div id="tab-generar" class="tab-content">
            <div class="form-container">
                <h2>Generar Nuevo Cuadrante</h2>
                
                <form id="form-generar">
                    <div class="form-group">
                        <label for="fecha-inicio">Fecha de inicio (se ajustará al lunes más cercano)</label>
                        <input type="date" id="fecha-inicio" class="input-field" required>
                    </div>

                    <div class="form-group">
                        <label for="num-semanas">Número de semanas</label>
                        <input type="number" id="num-semanas" class="input-field" min="1" max="52" value="4" required>
                    </div>

                    <div class="info-box">
                        <strong>Información:</strong>
                        <ul>
                            <li>El sistema asignará automáticamente los turnos respetando todas las restricciones</li>
                            <li><strong>Solo-mañanas:</strong> Van SIEMPRE a turno de mañana</li>
                            <li><strong>Rotadores:</strong> Se maximiza su presencia en mañana, pero reservando SIEMPRE mínimo 2 para tarde</li>
                            <li>Turno MAÑANA: Máximo 6 puestos (1 Lavado + 5 Pulido)</li>
                            <li>Turno TARDE: Mínimo 2, máximo 5 puestos (solo Pulido, NO hay lavado)</li>
                            <li>Se evita que alguien tenga 2 semanas seguidas de tarde o lavado (permitido si es inevitable)</li>
                            <li>Mínimo: 4 personas (2 solo-mañanas + 2 rotadores) | Máximo: 11 personas</li>
                        </ul>
                    </div>

                    <button type="submit" class="btn btn-primary">Generar Cuadrante</button>
                </form>

                <div style="margin-top: 30px; padding-top: 30px; border-top: 2px solid #e2e8f0;">
                    <h3 style="color: #e53e3e; margin-bottom: 15px;">⚠️ Zona de Peligro</h3>
                    <p style="margin-bottom: 15px; color: #718096;">
                        Resetear el sistema eliminará TODOS los cuadrantes generados y el histórico completo.
                        Esta acción NO se puede deshacer.
                    </p>
                    <button id="btn-reset" type="button" class="btn" style="background-color: #e53e3e; color: white;">
                        🗑️ Resetear Sistema Completo
                    </button>
                </div>
            </div>
        </div>

        <!-- PESTAÑA: Gestionar Personas -->
        <div id="tab-personas" class="tab-content">
            <div class="section-header">
                <h2>Gestión de Personal</h2>
                <button id="btn-nueva-persona" class="btn btn-primary">+ Nueva Persona</button>
            </div>

            <div style="margin-bottom: 20px; display: flex; gap: 10px; align-items: center;">
                <label style="font-weight: bold;">Filtrar por:</label>
                <select id="filtro-estado-personas" class="select-field" style="width: 200px;">
                    <option value="todos">Todos</option>
                    <option value="activos" selected>Solo Activos</option>
                    <option value="inactivos">Solo Inactivos</option>
                    <option value="bajas">Solo de Baja</option>
                </select>
            </div>

            <table id="tabla-personas" class="data-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>¿Puede Rotar?</th>
                        <th>¿Puede Lavar?</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Se llenará con JavaScript -->
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal para editar persona -->
    <div id="modal-persona" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2 id="modal-titulo">Nueva Persona</h2>
            
            <form id="form-persona">
                <input type="hidden" id="persona-id">
                
                <div class="form-group">
                    <label for="persona-nombre">Nombre</label>
                    <input type="text" id="persona-nombre" class="input-field" required>
                </div>

                <div class="form-group checkbox-group">
                    <label>
                        <input type="checkbox" id="persona-rotar" checked>
                        ¿Puede rotar turnos? (mañanas y tardes)
                    </label>
                </div>

                <div class="form-group checkbox-group">
                    <label>
                        <input type="checkbox" id="persona-lavar" checked>
                        ¿Puede ir al puesto de Lavado?
                    </label>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">Guardar</button>
                    <button type="button" class="btn btn-secondary" id="btn-cancelar">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal para editar asignación -->
    <div id="modal-asignacion" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Editar Asignación</h2>
            
            <form id="form-asignacion">
                <input type="hidden" id="asignacion-id">
                <input type="hidden" id="asignacion-fecha">
                <input type="hidden" id="asignacion-turno">
                
                <div class="form-group">
                    <label>Fecha y Turno</label>
                    <input type="text" id="asignacion-info" class="input-field" readonly>
                </div>

                <div class="form-group">
                    <label for="asignacion-persona">Persona</label>
                    <select id="asignacion-persona" class="select-field" required>
                        <!-- Se llenará con JavaScript -->
                    </select>
                </div>

                <div class="form-group">
                    <label for="asignacion-puesto">Puesto</label>
                    <select id="asignacion-puesto" class="select-field" required>
                        <option value="pulido1">Pulido 1</option>
                        <option value="pulido2">Pulido 2</option>
                        <option value="pulido3">Pulido 3</option>
                        <option value="pulido4">Pulido 4</option>
                        <option value="pulido5">Pulido 5</option>
                        <option value="lavado">Lavado</option>
                    </select>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">Guardar</button>
                    <button type="button" class="btn btn-secondary" id="btn-cancelar-asig">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal para marcar baja -->
    <div id="modal-baja" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Marcar Baja de Personal</h2>

            <form id="form-baja">
                <input type="hidden" id="baja-persona-id">

                <div class="form-group">
                    <label>Persona</label>
                    <input type="text" id="baja-persona-nombre" class="input-field" readonly>
                </div>

                <div class="form-group">
                    <label for="baja-fecha">Fecha de inicio de baja</label>
                    <input type="date" id="baja-fecha" class="input-field" required>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">Marcar Baja</button>
                    <button type="button" class="btn btn-secondary" id="btn-cancelar-baja">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal para verificar conflictos de baja -->
    <div id="modal-verificar-conflictos" class="modal">
        <div class="modal-content" style="max-width: 900px;">
            <span class="close">&times;</span>
            <h2>⚠️ Análisis de Impacto de Baja</h2>

            <div id="conflictos-content" style="max-height: 500px; overflow-y: auto;">
                <!-- Se llenará dinámicamente con JS -->
            </div>

            <div class="modal-actions" style="flex-wrap: wrap; gap: 10px; justify-content: space-between;">
                <button id="btn-regenerar-cuadrantes" class="btn btn-primary" style="flex: 1 1 45%;">🔄 Regenerar Cuadrantes Completos</button>
                <button id="btn-sustituir-criticas" class="btn btn-warning" style="flex: 1 1 45%;">⚠️ Solo Sustituir Semanas Ilegales</button>
                <button id="btn-marcar-sin-tocar" class="btn btn-secondary" style="flex: 1 1 45%;">✋ Marcar Baja Sin Hacer Nada</button>
                <button id="btn-cancelar-conflictos" class="btn btn-secondary" style="flex: 1 1 45%;">❌ Cancelar</button>
            </div>
        </div>
    </div>

    <!-- Modal para recuperar sustituciones al dar de alta -->
    <div id="modal-recuperar-sustituciones" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <span class="close">&times;</span>
            <h2>Recuperación de Baja</h2>

            <div id="sustituciones-content">
                <!-- Se llenará dinámicamente con JS -->
            </div>

            <div class="modal-actions">
                <button id="btn-confirmar-recuperar" class="btn btn-primary">Reincorporar a Turnos Seleccionados</button>
                <button id="btn-mantener-sustituciones" class="btn btn-secondary">Mantener Sustituciones</button>
                <button id="btn-cancelar-recuperar" class="btn btn-secondary">Cancelar</button>
            </div>
        </div>
    </div>

    <!-- Modal para incorporar persona al cuadrante -->
    <div id="modal-incorporar" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <span class="close">&times;</span>
            <h2>📅 Incorporar al Cuadrante</h2>

            <div id="incorporar-step1" style="display: block;">
                <p style="margin-bottom: 20px;">
                    Se incorporará a <strong id="incorporar-persona-nombre"></strong> a partir de una fecha seleccionada.
                </p>

                <div class="form-group">
                    <label for="incorporar-cuadrante">Cuadrante</label>
                    <select id="incorporar-cuadrante" class="select-field" required>
                        <option value="">Seleccionar cuadrante...</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="incorporar-fecha">Fecha de incorporación</label>
                    <input type="date" id="incorporar-fecha" class="input-field" required>
                    <small style="color: #666;">Se ajustará al lunes más cercano. Las semanas anteriores se mantendrán intactas.</small>
                </div>

                <div class="modal-actions">
                    <button id="btn-analizar-incorporacion" class="btn btn-primary">Analizar Impacto</button>
                    <button id="btn-cancelar-incorporar" class="btn btn-secondary">Cancelar</button>
                </div>
            </div>

            <div id="incorporar-step2" style="display: none;">
                <div id="incorporar-analisis-content">
                    <!-- Se llenará con el análisis -->
                </div>

                <div class="modal-actions">
                    <button id="btn-confirmar-incorporacion" class="btn btn-primary">✓ Regenerar e Incorporar</button>
                    <button id="btn-volver-incorporar" class="btn btn-secondary">← Volver</button>
                    <button id="btn-cancelar-incorporar2" class="btn btn-secondary">Cancelar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Área de impresión (oculta) -->
    <div id="print-area" class="print-only"></div>

    <!-- Toast Container -->
    <div id="toast-container" class="toast-container"></div>

    <script src="js/app.js"></script>
</body>
</html>