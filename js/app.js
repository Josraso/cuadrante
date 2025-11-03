// ==============================================
// VARIABLES GLOBALES
// ==============================================
let personas = [];
let cuadranteActual = null;
let asignaciones = [];
let loadingOverlay = null;

// ==============================================
// SISTEMA DE NOTIFICACIONES TOAST
// ==============================================
function showToast(message, type = 'info', duration = 4000) {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;

    const icons = {
        success: '✓',
        error: '✕',
        warning: '⚠',
        info: 'ℹ'
    };

    toast.innerHTML = `
        <span class="toast-icon">${icons[type] || icons.info}</span>
        <div class="toast-content">${message}</div>
        <span class="toast-close" onclick="this.parentElement.remove()">×</span>
    `;

    container.appendChild(toast);

    if (duration > 0) {
        setTimeout(() => {
            toast.classList.add('removing');
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }
}

// ==============================================
// SISTEMA DE LOADING
// ==============================================
function showLoading(message = 'Cargando...') {
    if (loadingOverlay) return; // Ya hay un loading activo

    loadingOverlay = document.createElement('div');
    loadingOverlay.className = 'loading-overlay';
    loadingOverlay.innerHTML = `
        <div style="text-align: center;">
            <div class="loading-spinner"></div>
            <div class="loading-text">${message}</div>
        </div>
    `;
    document.body.appendChild(loadingOverlay);
}

function hideLoading() {
    if (loadingOverlay) {
        loadingOverlay.remove();
        loadingOverlay = null;
    }
}

// ==============================================
// INICIALIZACIÓN
// ==============================================
document.addEventListener('DOMContentLoaded', function() {
    initTabs();
    initModals();
    cargarPersonas();
    cargarCuadrantes();
    
    // Event listeners
    document.getElementById('form-persona').addEventListener('submit', guardarPersona);
    document.getElementById('form-generar').addEventListener('submit', generarCuadrante);
    document.getElementById('form-asignacion').addEventListener('submit', guardarAsignacion);
    document.getElementById('form-baja').addEventListener('submit', confirmarBaja);
    document.getElementById('btn-nueva-persona').addEventListener('click', () => abrirModalPersona());
    document.getElementById('select-cuadrante').addEventListener('change', cargarCuadrante);
    document.getElementById('select-cuadrante-stats').addEventListener('change', cargarEstadisticas);
    document.getElementById('btn-exportar-csv').addEventListener('click', exportarCSV);
    document.getElementById('btn-imprimir').addEventListener('click', imprimirCuadrante);
    document.getElementById('btn-eliminar-cuadrante').addEventListener('click', eliminarCuadrante);
    document.getElementById('btn-reset').addEventListener('click', resetearSistema);

    // Filtros y búsqueda
    document.getElementById('buscar-persona').addEventListener('input', aplicarFiltros);
    document.getElementById('filtro-turno').addEventListener('change', aplicarFiltros);
    document.getElementById('btn-limpiar-filtros').addEventListener('click', limpiarFiltros);

    // Filtro de estado de personas
    document.getElementById('filtro-estado-personas').addEventListener('change', aplicarFiltroPersonas);

    // Establecer fecha de inicio por defecto (próximo lunes)
    const hoy = new Date();
    const proximoLunes = new Date(hoy);
    const dia = hoy.getDay();
    // Si es domingo (0), avanzar 1 día. Si es lunes (1), avanzar 7 días. Si no, calcular días hasta lunes
    const diasHastaLunes = dia === 0 ? 1 : (dia === 1 ? 7 : 8 - dia);
    proximoLunes.setDate(hoy.getDate() + diasHastaLunes);
    document.getElementById('fecha-inicio').valueAsDate = proximoLunes;
});

// ==============================================
// GESTIÓN DE PESTAÑAS
// ==============================================
function initTabs() {
    const tabBtns = document.querySelectorAll('.tab-btn');
    
    tabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const tabName = this.dataset.tab;
            
            // Ocultar todos los contenidos
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Desactivar todos los botones
            tabBtns.forEach(b => b.classList.remove('active'));
            
            // Activar la pestaña seleccionada
            document.getElementById('tab-' + tabName).classList.add('active');
            this.classList.add('active');
        });
    });
}

// ==============================================
// GESTIÓN DE MODALES
// ==============================================
function initModals() {
    const modales = document.querySelectorAll('.modal');
    const closeButtons = document.querySelectorAll('.close');
    
    closeButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            this.closest('.modal').style.display = 'none';
        });
    });
    
    window.addEventListener('click', function(e) {
        modales.forEach(modal => {
            if (e.target === modal) {
                modal.style.display = 'none';
            }
        });
    });
    
    document.getElementById('btn-cancelar').addEventListener('click', () => {
        document.getElementById('modal-persona').style.display = 'none';
    });
    
    document.getElementById('btn-cancelar-asig').addEventListener('click', () => {
        document.getElementById('modal-asignacion').style.display = 'none';
    });
}

// ==============================================
// GESTIÓN DE PERSONAS
// ==============================================
async function cargarPersonas() {
    try {
        const response = await fetch('api/personas.php');
        const data = await response.json();

        if (data.success) {
            personas = data.data;
            renderizarTablaPersonas();
        }
    } catch (error) {
        console.error('Error cargando personas:', error);
        showToast('Error al cargar las personas', 'error');
    }
}

function renderizarTablaPersonas(filtro = null) {
    const tbody = document.querySelector('#tabla-personas tbody');
    tbody.innerHTML = '';

    // Si no se pasa filtro, leer del dropdown
    if (filtro === null) {
        filtro = document.getElementById('filtro-estado-personas').value;
    }

    // Filtrar personas según el estado seleccionado
    let personasFiltradas = personas;
    if (filtro === 'activos') {
        personasFiltradas = personas.filter(p => p.activo == 1 && !p.fecha_baja);
    } else if (filtro === 'inactivos') {
        personasFiltradas = personas.filter(p => p.activo == 0);
    } else if (filtro === 'bajas') {
        personasFiltradas = personas.filter(p => p.fecha_baja !== null);
    }
    // 'todos' no filtra nada

    personasFiltradas.forEach((persona, index) => {
        const tr = document.createElement('tr');

        // Número correlativo (empezando desde 1)
        const numeroCorrelativo = index + 1;

        // Estado de baja
        const estaDeBaja = persona.fecha_baja !== null;
        const estaInactivo = persona.activo == 0;

        let estadoHTML = '';
        if (estaDeBaja) {
            estadoHTML = `<span style="color: #e53e3e;">🔴 De baja desde ${formatearFecha(persona.fecha_baja)}</span>`;
        } else if (estaInactivo) {
            estadoHTML = `<span style="color: #999;">⚫ Inactivo</span>`;
        } else {
            estadoHTML = `<span style="color: #48bb78;">✓ Activo</span>`;
        }

        // Botones según el estado
        let botonesHTML = `
            <button class="btn btn-secondary btn-small" onclick="abrirModalPersona(${persona.id})">
                Editar
            </button>
        `;

        if (estaInactivo) {
            // INACTIVO: Incorporar + Activar + Borrar Permanentemente
            botonesHTML += `
                <button class="btn" style="background-color: #667eea; color: white;" class="btn-small" onclick="incorporarPersona(${persona.id}, '${persona.nombre}')">
                    📅 Incorporar
                </button>
                <button class="btn" style="background-color: #48bb78; color: white;" class="btn-small" onclick="activarPersona(${persona.id}, '${persona.nombre}')">
                    Activar
                </button>
                <button class="btn btn-danger btn-small" onclick="eliminarPersona(${persona.id}, '${persona.nombre}')">
                    Borrar Permanentemente
                </button>
            `;
        } else if (estaDeBaja) {
            // DE BAJA: Recuperar + Desactivar + Borrar Permanentemente
            botonesHTML += `
                <button class="btn" style="background-color: #48bb78; color: white;" class="btn-small" onclick="quitarBaja(${persona.id}, '${persona.nombre}')">
                    Recuperar
                </button>
                <button class="btn" style="background-color: #718096; color: white;" class="btn-small" onclick="desactivarPersona(${persona.id}, '${persona.nombre}')">
                    Desactivar
                </button>
                <button class="btn btn-danger btn-small" onclick="eliminarPersona(${persona.id}, '${persona.nombre}')">
                    Borrar Permanentemente
                </button>
            `;
        } else {
            // ACTIVO: Marcar Baja + Desactivar + Borrar Permanentemente
            botonesHTML += `
                <button class="btn" style="background-color: #ed8936; color: white;" class="btn-small" onclick="marcarBaja(${persona.id}, '${persona.nombre}')">
                    Marcar Baja
                </button>
                <button class="btn" style="background-color: #718096; color: white;" class="btn-small" onclick="desactivarPersona(${persona.id}, '${persona.nombre}')">
                    Desactivar
                </button>
                <button class="btn btn-danger btn-small" onclick="eliminarPersona(${persona.id}, '${persona.nombre}')">
                    Borrar Permanentemente
                </button>
            `;
        }

        tr.innerHTML = `
            <td style="text-align: center; color: #667eea; font-weight: bold;">${numeroCorrelativo}</td>
            <td><strong>${persona.nombre}</strong></td>
            <td>
                <span class="badge ${persona.puede_rotar ? 'badge-yes' : 'badge-no'}">
                    ${persona.puede_rotar ? 'Si' : 'No'}
                </span>
            </td>
            <td>
                <span class="badge ${persona.puede_lavar ? 'badge-yes' : 'badge-no'}">
                    ${persona.puede_lavar ? 'Si' : 'No'}
                </span>
            </td>
            <td>${estadoHTML}</td>
            <td>${botonesHTML}</td>
        `;
        tbody.appendChild(tr);
    });
}

function abrirModalPersona(id = null) {
    const modal = document.getElementById('modal-persona');
    const form = document.getElementById('form-persona');
    form.reset();
    
    if (id) {
        const persona = personas.find(p => p.id === id);
        document.getElementById('modal-titulo').textContent = 'Editar Persona';
        document.getElementById('persona-id').value = persona.id;
        document.getElementById('persona-nombre').value = persona.nombre;
        document.getElementById('persona-rotar').checked = persona.puede_rotar == 1;
        document.getElementById('persona-lavar').checked = persona.puede_lavar == 1;
    } else {
        document.getElementById('modal-titulo').textContent = 'Nueva Persona';
        document.getElementById('persona-id').value = '';
    }
    
    modal.style.display = 'block';
}

async function guardarPersona(e) {
    e.preventDefault();

    const id = document.getElementById('persona-id').value;
    const datos = {
        nombre: document.getElementById('persona-nombre').value,
        puede_rotar: document.getElementById('persona-rotar').checked ? 1 : 0,
        puede_lavar: document.getElementById('persona-lavar').checked ? 1 : 0
    };

    if (id) {
        datos.id = id;
    }

    showLoading('Guardando persona...');

    try {
        const response = await fetch('api/personas.php', {
            method: id ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(datos)
        });

        const data = await response.json();
        hideLoading();

        if (data.success) {
            showToast(data.message, 'success');
            document.getElementById('modal-persona').style.display = 'none';
            cargarPersonas();
        } else {
            showToast(data.message, 'error');
        }
    } catch (error) {
        hideLoading();
        console.error('Error guardando persona:', error);
        showToast('Error al guardar', 'error');
    }
}

async function eliminarPersona(id, nombre) {
    if (!confirm(`¿Estás seguro de eliminar a ${nombre}?`)) return;

    showLoading('Eliminando persona...');

    try {
        const response = await fetch('api/personas.php', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });

        const data = await response.json();
        hideLoading();

        if (data.success) {
            showToast(data.message, 'success');
            cargarPersonas();
        } else {
            showToast(data.message, 'error');
        }
    } catch (error) {
        hideLoading();
        console.error('Error eliminando persona:', error);
        showToast('Error al eliminar', 'error');
    }
}

function marcarBaja(id, nombre) {
    // Abrir modal con calendario
    const modal = document.getElementById('modal-baja');
    const form = document.getElementById('form-baja');

    document.getElementById('baja-persona-id').value = id;
    document.getElementById('baja-persona-nombre').value = nombre;

    // Establecer fecha actual por defecto
    const hoy = new Date().toISOString().split('T')[0];
    document.getElementById('baja-fecha').value = hoy;

    modal.style.display = 'block';
}

async function confirmarBaja(e) {
    e.preventDefault();

    const id = document.getElementById('baja-persona-id').value;
    const fechaBaja = document.getElementById('baja-fecha').value;

    showLoading('Verificando conflictos...');

    // Cerrar modal de baja
    document.getElementById('modal-baja').style.display = 'none';

    try {
        // Primero verificar si hay conflictos
        const response = await fetch('api/verificar-baja.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ persona_id: id, fecha_baja: fechaBaja })
        });

        const data = await response.json();
        hideLoading();

        if (!data.success) {
            showToast(data.message, 'error');
            return;
        }

        // Siempre mostrar modal con análisis completo para que el usuario decida
        mostrarModalConflictos(id, fechaBaja, data);

    } catch (error) {
        hideLoading();
        console.error('Error verificando baja:', error);
        showToast('Error al verificar baja', 'error');
    }
}

async function ejecutarMarcadoBaja(personaId, fechaBaja) {
    showLoading('Marcando baja...');

    try {
        const response = await fetch('api/baja.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ persona_id: personaId, fecha_baja: fechaBaja })
        });

        const data = await response.json();
        hideLoading();

        if (data.success) {
            showToast(data.message, 'success');
            cargarPersonas();

            // Recargar cuadrante si hay uno seleccionado
            if (cuadranteActual) {
                cargarCuadrante();
            }
        } else {
            showToast(data.message, 'error');
        }
    } catch (error) {
        hideLoading();
        console.error('Error marcando baja:', error);
        showToast('Error al marcar baja', 'error');
    }
}

function mostrarModalConflictos(personaId, fechaBaja, data) {
    const modal = document.getElementById('modal-verificar-conflictos');
    const content = document.getElementById('conflictos-content');

    const { persona, afectaciones, total_semanas_ilegales, total_semanas_con_problemas, total_cuadrantes_afectados } = data;

    let html = `
        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <h3 style="margin: 0 0 10px 0; color: #495057;">📊 Resumen de Impacto</h3>
            <p style="margin: 5px 0;"><strong>Persona:</strong> ${persona.nombre}</p>
            <p style="margin: 5px 0;"><strong>Fecha de baja:</strong> ${formatearFecha(fechaBaja)}</p>
            <p style="margin: 5px 0;"><strong>Cuadrantes afectados:</strong> ${total_cuadrantes_afectados}</p>
            ${total_semanas_ilegales > 0 ? `
                <p style="margin: 5px 0; color: #dc3545; font-weight: bold;">
                    🔴 ${total_semanas_ilegales} semana(s) ILEGALES (menos de 2 de tarde)
                </p>
            ` : ''}
            ${total_semanas_con_problemas > 0 ? `
                <p style="margin: 5px 0; color: #ffc107;">
                    ⚠️ ${total_semanas_con_problemas} semana(s) con ADVERTENCIAS (exactamente 2 de tarde)
                </p>
            ` : ''}
        </div>
    `;

    afectaciones.forEach(cuadrante => {
        html += `
            <div style="margin: 20px 0;">
                <h3 style="color: #667eea; margin-bottom: 10px;">📅 ${cuadrante.cuadrante_nombre}</h3>
                <small style="color: #6c757d;">${formatearFecha(cuadrante.fecha_inicio)} - ${formatearFecha(cuadrante.fecha_fin)}</small>

                <table style="width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px;">
                    <thead>
                        <tr style="background: #e9ecef;">
                            <th style="padding: 8px; text-align: left; border: 1px solid #dee2e6;">Semana</th>
                            <th style="padding: 8px; text-align: center; border: 1px solid #dee2e6;">Actual<br><small>(M/T)</small></th>
                            <th style="padding: 8px; text-align: center; border: 1px solid #dee2e6;">Sin Persona<br><small>(M/T)</small></th>
                            <th style="padding: 8px; text-align: left; border: 1px solid #dee2e6;">Estado</th>
                            <th style="padding: 8px; text-align: left; border: 1px solid #dee2e6;">Candidatos</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        cuadrante.semanas.forEach(semana => {
            const bgColor = semana.es_ilegal ? '#fee' : (semana.tiene_problemas ? '#fff9e6' : '#fff');
            const icon = semana.es_ilegal ? '🔴' : (semana.tiene_problemas ? '⚠️' : '✅');

            html += `
                <tr style="background: ${bgColor};" data-cuadrante="${cuadrante.cuadrante_id}" data-lunes="${semana.lunes}" data-asignaciones='${JSON.stringify(semana.asignaciones.map(a => a.id))}'>
                    <td style="padding: 8px; border: 1px solid #dee2e6;">
                        <strong>${formatearFecha(semana.lunes)}</strong><br>
                        <small style="color: #6c757d;">al ${formatearFecha(semana.viernes)}</small>
                    </td>
                    <td style="padding: 8px; text-align: center; border: 1px solid #dee2e6;">
                        ${semana.distribucion_actual.manana} / ${semana.distribucion_actual.tarde}
                    </td>
                    <td style="padding: 8px; text-align: center; border: 1px solid #dee2e6; font-weight: bold;">
                        ${semana.distribucion_sin_persona.manana} / ${semana.distribucion_sin_persona.tarde}
                    </td>
                    <td style="padding: 8px; border: 1px solid #dee2e6;">
                        ${icon} ${semana.motivo}
                    </td>
                    <td style="padding: 8px; border: 1px solid #dee2e6;">
                        ${semana.candidatos.length > 0 ? `
                            <small>
                                ${semana.candidatos.slice(0, 3).map(c =>
                                    `${c.nombre} (${c.tardes_acumuladas} tardes)`
                                ).join('<br>')}
                            </small>
                        ` : '-'}
                    </td>
                </tr>
            `;
        });

        html += `
                    </tbody>
                </table>
            </div>
        `;
    });

    content.innerHTML = html;

    // Event listeners para los botones

    // OPCIÓN 1: Regenerar cuadrantes completos
    document.getElementById('btn-regenerar-cuadrantes').onclick = async () => {
        if (!confirm('¿Regenerar TODOS los cuadrantes afectados? Esto redistribuirá todas las asignaciones desde la fecha de baja SIN incluir a esta persona.')) {
            return;
        }

        modal.style.display = 'none';
        showLoading('Regenerando cuadrantes...');

        try {
            // Marcar baja primero
            await ejecutarMarcadoBaja(personaId, fechaBaja);

            // Regenerar cada cuadrante afectado usando api/regenerar.php
            for (const cuadrante of afectaciones) {
                const primeraSemanaBaja = cuadrante.semanas[0].lunes;

                const response = await fetch('api/regenerar.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        cuadrante_id: cuadrante.cuadrante_id,
                        fecha_desde: primeraSemanaBaja
                    })
                });

                const result = await response.json();
                if (!result.success) {
                    throw new Error(result.message);
                }
            }

            hideLoading();
            showToast('Baja marcada y cuadrantes regenerados correctamente', 'success');

            if (cuadranteActual) {
                cargarCuadrante();
            }
        } catch (error) {
            hideLoading();
            showToast('Error al regenerar: ' + error.message, 'error');
        }
    };

    // OPCIÓN 2: Solo sustituir semanas ilegales
    document.getElementById('btn-sustituir-criticas').onclick = async () => {
        modal.style.display = 'none';
        showLoading('Procesando...');

        try {
            // Marcar baja
            await ejecutarMarcadoBaja(personaId, fechaBaja);

            // Crear sustituciones solo para semanas ilegales
            let sustitucionesCreadas = 0;

            for (const cuadrante of afectaciones) {
                for (const semana of cuadrante.semanas) {
                    if (semana.es_ilegal && semana.candidatos.length > 0) {
                        await fetch('api/sustituir.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                asignaciones_ids: semana.asignaciones.map(a => a.id),
                                persona_sustituto_id: semana.candidatos[0].id
                            })
                        });
                        sustitucionesCreadas++;
                    }
                }
            }

            hideLoading();
            showToast(`Baja marcada y ${sustitucionesCreadas} semana(s) críticas sustituidas`, 'success');

            if (cuadranteActual) {
                cargarCuadrante();
            }
        } catch (error) {
            hideLoading();
            showToast('Error: ' + error.message, 'error');
        }
    };

    // OPCIÓN 3: Marcar baja sin hacer nada
    document.getElementById('btn-marcar-sin-tocar').onclick = async () => {
        if (!confirm('¿Marcar baja SIN regenerar ni sustituir? Las semanas ilegales quedarán sin cubrir.')) {
            return;
        }

        modal.style.display = 'none';
        await ejecutarMarcadoBaja(personaId, fechaBaja);
    };

    // OPCIÓN 4: Cancelar
    document.getElementById('btn-cancelar-conflictos').onclick = () => {
        modal.style.display = 'none';
    };

    modal.style.display = 'block';
}

async function quitarBaja(id, nombre) {
    showLoading('Verificando sustituciones...');

    try {
        // Verificar si hay sustituciones futuras
        const response = await fetch('api/recuperar-alta.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ persona_id: id, accion: 'consultar' })
        });

        const data = await response.json();
        hideLoading();

        if (!data.success) {
            showToast(data.message, 'error');
            return;
        }

        // Si no hay sustituciones, recuperar directamente
        if (!data.tiene_sustituciones) {
            if (confirm(`¿Recuperar a ${nombre} de la baja?`)) {
                await ejecutarRecuperacion(id, null);
            }
            return;
        }

        // Hay sustituciones - mostrar modal
        mostrarModalRecuperarSustituciones(id, nombre, data.sustituciones_futuras);

    } catch (error) {
        hideLoading();
        console.error('Error verificando sustituciones:', error);
        showToast('Error al verificar sustituciones', 'error');
    }
}

async function ejecutarRecuperacion(personaId, semanasSeleccionadas) {
    showLoading('Quitando baja...');

    try {
        // Quitar baja
        const response = await fetch('api/baja.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ persona_id: personaId, fecha_baja: null })
        });

        const data = await response.json();

        if (!data.success) {
            hideLoading();
            showToast(data.message, 'error');
            return;
        }

        // Si hay semanas seleccionadas, eliminar esas sustituciones
        if (semanasSeleccionadas && semanasSeleccionadas.length > 0) {
            const response2 = await fetch('api/recuperar-alta.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    persona_id: personaId,
                    accion: 'eliminar',
                    semanas: semanasSeleccionadas
                })
            });

            const data2 = await response2.json();
            hideLoading();

            if (data2.success) {
                showToast(data2.message, 'success');
            } else {
                showToast(data2.message, 'warning');
            }
        } else {
            hideLoading();
            showToast(data.message, 'success');
        }

        cargarPersonas();

        // Recargar cuadrante si hay uno seleccionado
        if (cuadranteActual) {
            cargarCuadrante();
        }

    } catch (error) {
        hideLoading();
        console.error('Error recuperando:', error);
        showToast('Error al recuperar', 'error');
    }
}

function mostrarModalRecuperarSustituciones(personaId, nombre, semanas) {
    const modal = document.getElementById('modal-recuperar-sustituciones');
    const content = document.getElementById('sustituciones-content');

    let html = `
        <p style="margin-bottom: 15px;">
            <strong>${nombre}</strong> tiene <strong>${semanas.length} semana(s)</strong> sustituidas en el futuro.
            ¿Deseas reincorporar a esta persona a esos turnos?
        </p>
    `;

    semanas.forEach(semana => {
        html += `
            <div style="background: #f8f9fa; border-left: 4px solid #667eea; padding: 10px; margin: 10px 0; border-radius: 4px;">
                <label style="display: flex; align-items: center; cursor: pointer;">
                    <input type="checkbox" class="semana-reincorporar"
                           data-lunes="${semana.lunes}"
                           checked
                           style="margin-right: 10px;">
                    <div style="flex: 1;">
                        <strong>Semana ${formatearFecha(semana.lunes)} - ${formatearFecha(semana.viernes)}</strong>
                        <br>
                        <small>Cuadrante: ${semana.cuadrante_nombre}</small>
                        <br>
                        <small style="color: #28a745;">
                            ${semana.asignaciones.length} asignación(es) actualmente cubiertas por:
                            ${[...new Set(semana.asignaciones.map(a => a.sustituto_nombre))].join(', ')}
                        </small>
                    </div>
                </label>
            </div>
        `;
    });

    content.innerHTML = html;

    // Event listeners para los botones
    document.getElementById('btn-confirmar-recuperar').onclick = async () => {
        modal.style.display = 'none';

        // Obtener semanas seleccionadas
        const checkboxes = document.querySelectorAll('.semana-reincorporar:checked');
        const semanasSeleccionadas = Array.from(checkboxes).map(cb => cb.dataset.lunes);

        await ejecutarRecuperacion(personaId, semanasSeleccionadas);
    };

    document.getElementById('btn-mantener-sustituciones').onclick = async () => {
        modal.style.display = 'none';
        await ejecutarRecuperacion(personaId, null); // Solo quitar baja, mantener sustituciones
    };

    document.getElementById('btn-cancelar-recuperar').onclick = () => {
        modal.style.display = 'none';
    };

    modal.style.display = 'block';
}

async function activarPersona(id, nombre) {
    if (!confirm(`¿Activar a ${nombre}?`)) return;

    showLoading('Activando persona...');

    try {
        const response = await fetch('api/activar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, accion: 'activar' })
        });

        const data = await response.json();
        hideLoading();

        if (data.success) {
            showToast(data.message, 'success');
            cargarPersonas();
        } else {
            showToast(data.message, 'error');
        }
    } catch (error) {
        hideLoading();
        console.error('Error activando persona:', error);
        showToast('Error al activar', 'error');
    }
}

async function desactivarPersona(id, nombre) {
    if (!confirm(`¿Desactivar a ${nombre}?\n\nLa persona dejará de aparecer en los cuadrantes nuevos pero seguirá en el sistema.`)) return;

    showLoading('Desactivando persona...');

    try {
        const response = await fetch('api/activar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, accion: 'desactivar' })
        });

        const data = await response.json();
        hideLoading();

        if (data.success) {
            showToast(data.message, 'success');
            cargarPersonas();
        } else {
            showToast(data.message, 'error');
        }
    } catch (error) {
        hideLoading();
        console.error('Error desactivando persona:', error);
        showToast('Error al desactivar', 'error');
    }
}

function aplicarFiltroPersonas() {
    renderizarTablaPersonas();
}

// ==============================================
// GENERACIÓN DE CUADRANTES
// ==============================================
async function generarCuadrante(e) {
    e.preventDefault();

    const fechaInicio = document.getElementById('fecha-inicio').value;
    const numSemanas = parseInt(document.getElementById('num-semanas').value);

    if (!fechaInicio || numSemanas < 1) {
        showToast('Por favor completa todos los campos', 'warning');
        return;
    }

    if (!confirm(`¿Generar cuadrante de ${numSemanas} semana(s) desde ${fechaInicio}?`)) {
        return;
    }

    showLoading('Generando cuadrante...');

    try {
        const response = await fetch('api/generar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ fecha_inicio: fechaInicio, num_semanas: numSemanas })
        });

        const data = await response.json();
        hideLoading();

        if (data.success) {
            // Mostrar mensaje de éxito
            if (data.advertencias && data.advertencias.length > 0) {
                // Hay advertencias - mostrar mensaje warning
                showToast(data.message, 'warning', 6000);

                // Mostrar cada advertencia
                setTimeout(() => {
                    data.advertencias.forEach((adv, index) => {
                        setTimeout(() => {
                            showToast(adv, 'warning', 8000);
                        }, index * 500);
                    });
                }, 500);
            } else {
                // Sin advertencias - mensaje normal
                showToast('Cuadrante generado correctamente', 'success');
            }

            cargarCuadrantes();
            // Cambiar a la pestaña de cuadrante
            document.querySelector('[data-tab="cuadrante"]').click();
        } else {
            showToast('Error: ' + data.message, 'error');
        }
    } catch (error) {
        hideLoading();
        console.error('Error generando cuadrante:', error);
        showToast('Error al generar el cuadrante', 'error');
    }
}

// ==============================================
// VISUALIZACIÓN DE CUADRANTES
// ==============================================
async function cargarCuadrantes() {
    try {
        const response = await fetch('api/cuadrante.php');
        const data = await response.json();

        if (data.success) {
            // Llenar select de cuadrante principal
            const select = document.getElementById('select-cuadrante');
            select.innerHTML = '<option value="">Seleccionar cuadrante...</option>';

            // Llenar select de estadísticas
            const selectStats = document.getElementById('select-cuadrante-stats');
            selectStats.innerHTML = '<option value="">Seleccionar cuadrante...</option>';

            data.data.forEach(cuadrante => {
                const option1 = document.createElement('option');
                option1.value = cuadrante.id;
                option1.textContent = `${cuadrante.nombre} (${cuadrante.num_semanas} semanas)`;
                select.appendChild(option1);

                const option2 = document.createElement('option');
                option2.value = cuadrante.id;
                option2.textContent = `${cuadrante.nombre} (${cuadrante.num_semanas} semanas)`;
                selectStats.appendChild(option2);
            });
        }
    } catch (error) {
        console.error('Error cargando cuadrantes:', error);
    }
}

async function cargarCuadrante() {
    const id = document.getElementById('select-cuadrante').value;

    if (!id) {
        document.getElementById('cuadrante-display').innerHTML =
            '<p class="info-message">Selecciona un cuadrante para visualizar</p>';
        document.getElementById('btn-exportar-csv').style.display = 'none';
        document.getElementById('btn-imprimir').style.display = 'none';
        document.getElementById('btn-eliminar-cuadrante').style.display = 'none';
        document.getElementById('filtros-container').style.display = 'none';
        return;
    }

    showLoading('Cargando cuadrante...');

    try {
        const response = await fetch(`api/cuadrante.php?id=${id}`);
        const data = await response.json();
        hideLoading();

        if (data.success) {
            cuadranteActual = data.data;
            asignaciones = data.data.asignaciones;
            renderizarCuadrante();
            document.getElementById('btn-exportar-csv').style.display = 'inline-block';
            document.getElementById('btn-imprimir').style.display = 'inline-block';
            document.getElementById('btn-eliminar-cuadrante').style.display = 'inline-block';
            document.getElementById('filtros-container').style.display = 'block';
            limpiarFiltros();
        }
    } catch (error) {
        hideLoading();
        console.error('Error cargando cuadrante:', error);
        showToast('Error al cargar el cuadrante', 'error');
    }
}

async function eliminarCuadrante() {
    if (!cuadranteActual) return;

    if (!confirm(`¿Estás seguro de eliminar el cuadrante "${cuadranteActual.nombre}"?`)) {
        return;
    }

    showLoading('Eliminando cuadrante...');

    try {
        const response = await fetch('api/cuadrante.php', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: cuadranteActual.id })
        });

        const data = await response.json();
        hideLoading();

        if (data.success) {
            showToast('Cuadrante eliminado correctamente', 'success');
            document.getElementById('select-cuadrante').value = '';
            document.getElementById('cuadrante-display').innerHTML =
                '<p class="info-message">Selecciona un cuadrante para visualizar</p>';
            document.getElementById('btn-exportar-csv').style.display = 'none';
            document.getElementById('btn-imprimir').style.display = 'none';
            document.getElementById('btn-eliminar-cuadrante').style.display = 'none';
            cuadranteActual = null;
            cargarCuadrantes();
        } else {
            showToast('Error: ' + data.message, 'error');
        }
    } catch (error) {
        hideLoading();
        console.error('Error eliminando cuadrante:', error);
        showToast('Error al eliminar el cuadrante', 'error');
    }
}

function verificarBaja(personaId, fecha) {
    // Buscar persona en el array global
    const persona = personas.find(p => p.id === personaId);

    if (!persona || !persona.fecha_baja) {
        return false;
    }

    // Comparar fechas (si fecha >= fecha_baja, está de baja)
    return fecha >= persona.fecha_baja;
}

function renderizarCuadrante() {
    const container = document.getElementById('cuadrante-display');

    if (!cuadranteActual) return;

    // Agrupar asignaciones por semana
    const semanas = agruparPorSemanas(asignaciones);

    let html = `
        <div class="cuadrante-info">
            <h3>${cuadranteActual.nombre}</h3>
            <p><strong>Periodo:</strong> ${formatearFecha(cuadranteActual.fecha_inicio)} - ${formatearFecha(cuadranteActual.fecha_fin)}</p>
            <p><strong>Total semanas:</strong> ${cuadranteActual.num_semanas}</p>
        </div>
    `;

    semanas.forEach((semana, index) => {
        html += renderizarSemana(semana, index + 1);
    });

    container.innerHTML = html;
}

function agruparPorSemanas(asignaciones) {
    const semanas = {};

    // Solo incluir asignaciones originales (no sustituciones)
    asignaciones.forEach(asig => {
        if (asig.es_sustitucion == 0) {
            const lunes = getLunesFromDate(asig.fecha);

            if (!semanas[lunes]) {
                semanas[lunes] = [];
            }
            semanas[lunes].push(asig);
        }
    });

    return Object.entries(semanas).sort((a, b) => a[0].localeCompare(b[0]));
}

function getLunesFromDate(fecha) {
    // Usar zona horaria local para evitar desfases
    const d = new Date(fecha + 'T12:00:00'); // Mediodía para evitar problemas de zona horaria
    const day = d.getDay(); // 0=domingo, 1=lunes, ..., 6=sábado
    // Calcular días a restar para llegar al lunes de esa semana
    const diasARestar = day === 0 ? 6 : day - 1;
    d.setDate(d.getDate() - diasARestar);
    return d.toISOString().split('T')[0];
}

// Función auxiliar para renderizar asignación con sustitución
function renderizarAsignacionConSustituto(asig, claseAdicional = '') {
    const estaDeBaja = verificarBaja(asig.persona_id, asig.fecha);

    // Si tiene sustituto, mostrar stack
    if (asig.sustituto) {
        return `
            <div class="asignacion-stack">
                <div class="asignacion ${claseAdicional} asignacion-sustituida" onclick="abrirModalAsignacion(${asig.id})">
                    ${asig.persona_nombre} ⚠️
                </div>
                <div class="asignacion ${claseAdicional} asignacion-sustituto" onclick="abrirModalAsignacion(${asig.sustituto.id})">
                    ${asig.sustituto.persona_nombre} (cubre)
                </div>
            </div>
        `;
    }

    // Si no tiene sustituto pero está de baja, mostrar con estilo de baja
    if (estaDeBaja) {
        return `
            <div class="asignacion ${claseAdicional} asignacion-baja" onclick="abrirModalAsignacion(${asig.id})">
                ${asig.persona_nombre} ⚠️
            </div>
        `;
    }

    // Normal
    return `
        <div class="asignacion ${claseAdicional}" onclick="abrirModalAsignacion(${asig.id})">
            ${asig.persona_nombre}
        </div>
    `;
}

function renderizarSemana(semana, numSemana) {
    const [lunes, asignacionesSemana] = semana;
    const dias = obtenerDiasSemana(lunes);

    let html = `
        <div class="cuadrante-semana">
            <div class="semana-header">
                Semana ${numSemana}: ${formatearFecha(lunes)} - ${formatearFecha(dias[4])}
            </div>
            <table class="cuadrante-tabla">
                <thead>
                    <tr>
                        <th style="width: 120px;">Puesto</th>
    `;

    dias.forEach((dia, index) => {
        const nombreDia = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes'][index];
        html += `<th>${nombreDia}<br><small>${formatearFecha(dia)}</small></th>`;
    });

    html += `
                    </tr>
                </thead>
                <tbody>
    `;

    // TURNO DE MAÑANA
    html += '<tr><td colspan="6" class="turno-header">TURNO DE MAÑANA</td></tr>';

    // Lavado
    html += '<tr><td class="puesto-label-cell">Lavado</td>';
    dias.forEach(dia => {
        const asigsDia = asignacionesSemana.filter(a => a.fecha === dia && a.turno === 'mañana' && a.puesto === 'lavado');
        html += '<td>';
        asigsDia.forEach(asig => {
            html += renderizarAsignacionConSustituto(asig, 'asignacion-lavado');
        });
        html += '</td>';
    });
    html += '</tr>';

    // Pulidos (hasta 5 puestos disponibles)
    const puestos = ['pulido1', 'pulido2', 'pulido3', 'pulido4', 'pulido5'];
    puestos.forEach(puesto => {
        html += `<tr><td class="puesto-label-cell">${formatearPuesto(puesto)}</td>`;
        dias.forEach(dia => {
            const asigsDia = asignacionesSemana.filter(a => a.fecha === dia && a.turno === 'mañana' && a.puesto === puesto);
            html += '<td>';
            asigsDia.forEach(asig => {
                html += renderizarAsignacionConSustituto(asig);
            });
            html += '</td>';
        });
        html += '</tr>';
    });

    // TURNO DE TARDE
    html += '<tr><td colspan="6" class="turno-header">TURNO DE TARDE</td></tr>';

    puestos.forEach(puesto => {
        html += `<tr><td class="puesto-label-cell">${formatearPuesto(puesto)}</td>`;
        dias.forEach(dia => {
            const asigsDia = asignacionesSemana.filter(a => a.fecha === dia && a.turno === 'tarde' && a.puesto === puesto);
            html += '<td>';
            asigsDia.forEach(asig => {
                html += renderizarAsignacionConSustituto(asig, 'asignacion-tarde');
            });
            html += '</td>';
        });
        html += '</tr>';
    });

    html += `
                </tbody>
            </table>
        </div>
    `;

    return html;
}

function obtenerDiasSemana(lunes) {
    const dias = [];
    const fecha = new Date(lunes + 'T12:00:00'); // Mediodía para evitar problemas de zona horaria

    for (let i = 0; i < 5; i++) {
        dias.push(fecha.toISOString().split('T')[0]);
        fecha.setDate(fecha.getDate() + 1);
    }

    return dias;
}

function formatearFecha(fecha) {
    const d = new Date(fecha + 'T12:00:00'); // Mediodía para evitar problemas de zona horaria
    return d.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function formatearPuesto(puesto) {
    const nombres = {
        'pulido1': 'Pulido 1',
        'pulido2': 'Pulido 2',
        'pulido3': 'Pulido 3',
        'pulido4': 'Pulido 4',
        'pulido5': 'Pulido 5',
        'lavado': 'Lavado'
    };
    return nombres[puesto] || puesto;
}

// ==============================================
// EDICIÓN DE ASIGNACIONES
// ==============================================
function abrirModalAsignacion(asignacionId) {
    const asig = asignaciones.find(a => a.id === asignacionId);
    if (!asig) return;
    
    const modal = document.getElementById('modal-asignacion');
    
    document.getElementById('asignacion-id').value = asig.id;
    document.getElementById('asignacion-fecha').value = asig.fecha;
    document.getElementById('asignacion-turno').value = asig.turno;
    document.getElementById('asignacion-info').value = 
        `${formatearFecha(asig.fecha)} - ${asig.turno.charAt(0).toUpperCase() + asig.turno.slice(1)}`;
    
    // Llenar select de personas
    const selectPersona = document.getElementById('asignacion-persona');
    selectPersona.innerHTML = '';
    personas.forEach(p => {
        const option = document.createElement('option');
        option.value = p.id;
        option.textContent = p.nombre;
        if (p.id == asig.persona_id) option.selected = true;
        selectPersona.appendChild(option);
    });
    
    document.getElementById('asignacion-puesto').value = asig.puesto;
    
    modal.style.display = 'block';
}

async function guardarAsignacion(e) {
    e.preventDefault();

    const datos = {
        id: document.getElementById('asignacion-id').value,
        persona_id: document.getElementById('asignacion-persona').value,
        puesto: document.getElementById('asignacion-puesto').value
    };

    showLoading('Guardando asignación...');

    try {
        const response = await fetch('api/cuadrante.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(datos)
        });

        const data = await response.json();
        hideLoading();

        if (data.success) {
            showToast('Asignación actualizada', 'success');
            document.getElementById('modal-asignacion').style.display = 'none';
            cargarCuadrante();
        } else {
            showToast('Error: ' + data.message, 'error');
        }
    } catch (error) {
        hideLoading();
        console.error('Error actualizando asignación:', error);
        showToast('Error al actualizar', 'error');
    }
}

// ==============================================
// FILTROS Y BÚSQUEDA
// ==============================================
function aplicarFiltros() {
    const busqueda = document.getElementById('buscar-persona').value.toLowerCase().trim();
    const filtroTurno = document.getElementById('filtro-turno').value;

    const asignacionesElements = document.querySelectorAll('.asignacion');

    let tieneResultados = false;

    asignacionesElements.forEach(asigEl => {
        const nombrePersona = asigEl.textContent.toLowerCase().trim();

        // Obtener el turno desde el padre (verificar si está en turno de tarde)
        let turnoAsignacion = 'mañana';
        if (asigEl.classList.contains('asignacion-tarde')) {
            turnoAsignacion = 'tarde';
        }

        // Verificar si coincide con los filtros
        const coincideNombre = !busqueda || nombrePersona.includes(busqueda);
        const coincideTurno = !filtroTurno || turnoAsignacion === filtroTurno;

        if (coincideNombre && coincideTurno) {
            asigEl.classList.remove('dimmed');
            asigEl.classList.add('highlight');
            tieneResultados = true;
        } else {
            asigEl.classList.add('dimmed');
            asigEl.classList.remove('highlight');
        }
    });

    // Quitar highlight después de 1 segundo si hay búsqueda activa
    if (busqueda || filtroTurno) {
        setTimeout(() => {
            document.querySelectorAll('.asignacion.highlight').forEach(el => {
                el.classList.remove('highlight');
            });
        }, 1000);
    }
}

function limpiarFiltros() {
    document.getElementById('buscar-persona').value = '';
    document.getElementById('filtro-turno').value = '';

    const asignacionesElements = document.querySelectorAll('.asignacion');
    asignacionesElements.forEach(asigEl => {
        asigEl.classList.remove('dimmed', 'highlight');
    });

    showToast('Filtros limpiados', 'info', 2000);
}

// ==============================================
// ESTADÍSTICAS
// ==============================================
async function cargarEstadisticas() {
    const id = document.getElementById('select-cuadrante-stats').value;

    if (!id) {
        document.getElementById('estadisticas-display').innerHTML =
            '<p class="info-message">Selecciona un cuadrante para ver las estadísticas</p>';
        return;
    }

    showLoading('Cargando estadísticas...');

    try {
        const response = await fetch(`api/cuadrante.php?id=${id}`);
        const data = await response.json();
        hideLoading();

        if (data.success) {
            renderizarEstadisticas(data.data);
        }
    } catch (error) {
        hideLoading();
        console.error('Error cargando estadísticas:', error);
        showToast('Error al cargar las estadísticas', 'error');
    }
}

function renderizarEstadisticas(cuadrante) {
    const asignaciones = cuadrante.asignaciones;

    // Calcular estadísticas POR SEMANA (no por día)
    const stats = {};
    const semanasPorPersona = {}; // Para rastrear semanas únicas
    let totalSemanas = 0;

    // Agrupar asignaciones por persona y semana
    asignaciones.forEach(asig => {
        const fecha = new Date(asig.fecha + 'T00:00:00');
        // Calcular el lunes de esa semana
        const diaSemana = fecha.getDay();
        const diffAlLunes = (diaSemana === 0 ? -6 : 1 - diaSemana);
        const lunes = new Date(fecha);
        lunes.setDate(fecha.getDate() + diffAlLunes);
        const claveeSemana = lunes.toISOString().split('T')[0];

        if (!stats[asig.persona_nombre]) {
            stats[asig.persona_nombre] = {
                total: 0,
                manana: 0,
                tarde: 0,
                lavado: 0
            };
            semanasPorPersona[asig.persona_nombre] = {
                semanas: new Set(),
                semanasManana: new Set(),
                semanasTarde: new Set(),
                semanasLavado: new Set()
            };
        }

        // Registrar semana
        semanasPorPersona[asig.persona_nombre].semanas.add(claveeSemana);

        if (asig.turno === 'mañana') {
            semanasPorPersona[asig.persona_nombre].semanasManana.add(claveeSemana);
            if (asig.puesto === 'lavado') {
                semanasPorPersona[asig.persona_nombre].semanasLavado.add(claveeSemana);
            }
        } else {
            semanasPorPersona[asig.persona_nombre].semanasTarde.add(claveeSemana);
        }
    });

    // Calcular totales por persona EN SEMANAS
    let totalMananas = 0;
    let totalTardes = 0;
    let totalLavados = 0;

    Object.keys(semanasPorPersona).forEach(nombre => {
        stats[nombre].total = semanasPorPersona[nombre].semanas.size;
        stats[nombre].manana = semanasPorPersona[nombre].semanasManana.size;
        stats[nombre].tarde = semanasPorPersona[nombre].semanasTarde.size;
        stats[nombre].lavado = semanasPorPersona[nombre].semanasLavado.size;

        totalMananas += stats[nombre].manana;
        totalTardes += stats[nombre].tarde;
        totalLavados += stats[nombre].lavado;
    });

    // Contar semanas únicas totales
    const semanasUnicas = new Set();
    asignaciones.forEach(asig => {
        const fecha = new Date(asig.fecha + 'T00:00:00');
        const diaSemana = fecha.getDay();
        const diffAlLunes = (diaSemana === 0 ? -6 : 1 - diaSemana);
        const lunes = new Date(fecha);
        lunes.setDate(fecha.getDate() + diffAlLunes);
        semanasUnicas.add(lunes.toISOString().split('T')[0]);
    });
    totalSemanas = semanasUnicas.size;

    // Ordenar por turnos de tarde (descendente)
    const personasOrdenadas = Object.entries(stats).sort((a, b) => b[1].tarde - a[1].tarde);

    // Generar HTML
    const totalSemanasAsignadas = totalMananas + totalTardes;
    let html = `
        <div class="stats-container">
            <div class="stat-card">
                <h3>Total Semanas</h3>
                <div class="stat-value">${totalSemanas}</div>
                <div class="stat-label">Semanas en cuadrante</div>
            </div>
            <div class="stat-card">
                <h3>Semanas Asignadas</h3>
                <div class="stat-value">${totalSemanasAsignadas}</div>
                <div class="stat-label">Total asignaciones</div>
            </div>
            <div class="stat-card">
                <h3>Semanas Mañana</h3>
                <div class="stat-value">${totalMananas}</div>
                <div class="stat-label">${((totalMananas/totalSemanasAsignadas)*100).toFixed(1)}% del total</div>
            </div>
            <div class="stat-card">
                <h3>Semanas Tarde</h3>
                <div class="stat-value">${totalTardes}</div>
                <div class="stat-label">${((totalTardes/totalSemanasAsignadas)*100).toFixed(1)}% del total</div>
            </div>
        </div>

        <h3 style="margin: 30px 0 15px 0; color: #667eea;">Distribución por Persona (en Semanas)</h3>
        <table class="stats-table">
            <thead>
                <tr>
                    <th>Persona</th>
                    <th>Total Semanas</th>
                    <th>Mañanas</th>
                    <th>Tardes</th>
                    <th>Lavados</th>
                    <th>Distribución</th>
                </tr>
            </thead>
            <tbody>
    `;

    const maxTurnos = Math.max(...personasOrdenadas.map(p => p[1].total));

    personasOrdenadas.forEach(([nombre, datos]) => {
        const porcentajeManana = datos.total > 0 ? (datos.manana / datos.total * 100).toFixed(1) : 0;
        const porcentajeTarde = datos.total > 0 ? (datos.tarde / datos.total * 100).toFixed(1) : 0;

        html += `
            <tr>
                <td><strong>${nombre}</strong></td>
                <td>${datos.total}</td>
                <td>${datos.manana}</td>
                <td>${datos.tarde}</td>
                <td>${datos.lavado}</td>
                <td>
                    <div style="display: flex; gap: 5px; align-items: center;">
                        <div class="stat-bar" style="flex: 1;">
                            <div class="stat-bar-fill manana" style="width: ${porcentajeManana}%">
                                ${datos.manana > 0 ? datos.manana + 'M' : ''}
                            </div>
                        </div>
                        <div class="stat-bar" style="flex: 1;">
                            <div class="stat-bar-fill tarde" style="width: ${porcentajeTarde}%">
                                ${datos.tarde > 0 ? datos.tarde + 'T' : ''}
                            </div>
                        </div>
                    </div>
                </td>
            </tr>
        `;
    });

    html += `
            </tbody>
        </table>
    `;

    document.getElementById('estadisticas-display').innerHTML = html;
}

// ==============================================
// EXPORTACIÓN
// ==============================================
function exportarCSV() {
    if (!cuadranteActual) {
        showToast('No hay cuadrante seleccionado', 'warning');
        return;
    }

    // Crear contenido CSV
    let csv = '\uFEFF'; // BOM para UTF-8
    csv += `Cuadrante: ${cuadranteActual.nombre}\n`;
    csv += `Periodo: ${formatearFecha(cuadranteActual.fecha_inicio)} - ${formatearFecha(cuadranteActual.fecha_fin)}\n`;
    csv += `Total semanas: ${cuadranteActual.num_semanas}\n\n`;

    // Encabezados
    csv += 'Fecha,Día,Turno,Puesto,Persona\n';

    // Ordenar asignaciones por fecha
    const asignacionesOrdenadas = [...asignaciones].sort((a, b) => a.fecha.localeCompare(b.fecha));

    // Añadir datos
    asignacionesOrdenadas.forEach(asig => {
        const fecha = new Date(asig.fecha + 'T12:00:00');
        const nombreDia = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'][fecha.getDay()];
        const turno = asig.turno.charAt(0).toUpperCase() + asig.turno.slice(1);
        const puesto = formatearPuesto(asig.puesto);

        csv += `${formatearFecha(asig.fecha)},${nombreDia},${turno},${puesto},${asig.persona_nombre}\n`;
    });

    // Crear blob y descargar
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);

    link.setAttribute('href', url);
    link.setAttribute('download', `cuadrante_${cuadranteActual.fecha_inicio}.csv`);
    link.style.display = 'none';

    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    showToast('Cuadrante exportado correctamente', 'success');
}

// ==============================================
// IMPRESIÓN
// ==============================================
function imprimirCuadrante() {
    const printArea = document.getElementById('print-area');
    const cuadranteDisplay = document.getElementById('cuadrante-display').cloneNode(true);
    
    // Eliminar eventos onclick de las asignaciones
    cuadranteDisplay.querySelectorAll('.asignacion').forEach(el => {
        el.removeAttribute('onclick');
    });
    
    printArea.innerHTML = cuadranteDisplay.innerHTML;
    window.print();
}

// ==============================================
// RESETEAR SISTEMA
// ==============================================
async function resetearSistema() {
    // Confirmar con el usuario
    const confirmacion = confirm(
        '⚠️ ADVERTENCIA ⚠️\n\n' +
        'Esto eliminará PERMANENTEMENTE:\n' +
        '• Todos los cuadrantes generados\n' +
        '• Todo el histórico de turnos\n\n' +
        'Esta acción NO se puede deshacer.\n\n' +
        '¿Estás seguro de que quieres continuar?'
    );

    if (!confirmacion) {
        return;
    }

    // Segunda confirmación
    const segundaConfirmacion = confirm(
        '¿REALMENTE estás seguro?\n\n' +
        'Esta es tu última oportunidad para cancelar.'
    );

    if (!segundaConfirmacion) {
        return;
    }

    showLoading('Reseteando sistema...');

    try {
        const response = await fetch('api/reset.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            }
        });

        const data = await response.json();
        hideLoading();

        if (data.success) {
            showToast('Sistema reseteado correctamente. Todos los datos han sido eliminados.', 'success', 5000);

            // Recargar listas
            await cargarCuadrantes();
            cargarEstadisticas();

            // Limpiar visualización
            document.getElementById('cuadrante-display').innerHTML =
                '<p class="info-message">Selecciona un cuadrante para visualizarlo</p>';
            document.getElementById('estadisticas-display').innerHTML =
                '<p class="info-message">Selecciona un cuadrante para ver las estadísticas</p>';

            cuadranteActual = null;
            asignaciones = [];
        } else {
            showToast(data.message || 'Error al resetear el sistema', 'error');
        }
    } catch (error) {
        hideLoading();
        console.error('Error:', error);
        showToast('Error de conexión al resetear el sistema', 'error');
    }
}

// ==============================================
// SISTEMA DE INCORPORACIÓN DE PERSONAS
// ==============================================

let personaIncorporarId = null;
let cuadranteIncorporarId = null;
let fechaIncorporarDesde = null;

async function incorporarPersona(id, nombre) {
    personaIncorporarId = id;

    showLoading('Verificando cuadrantes activos...');

    try {
        const response = await fetch('api/incorporar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ persona_id: id, accion: 'consultar' })
        });

        const data = await response.json();
        hideLoading();

        if (!data.success) {
            showToast(data.message, 'error');
            return;
        }

        if (!data.tiene_cuadrantes_activos) {
            showToast('No hay cuadrantes activos para incorporar', 'warning');
            return;
        }

        // Abrir modal y llenar select de cuadrantes
        document.getElementById('incorporar-persona-nombre').textContent = nombre;
        const select = document.getElementById('incorporar-cuadrante');
        select.innerHTML = '<option value="">Seleccionar cuadrante...</option>';

        data.cuadrantes.forEach(c => {
            const option = document.createElement('option');
            option.value = c.id;
            option.textContent = `${c.nombre} (${formatearFecha(c.fecha_inicio)} - ${formatearFecha(c.fecha_fin)})`;
            select.appendChild(option);
        });

        // Establecer fecha por defecto (hoy)
        const hoy = new Date().toISOString().split('T')[0];
        document.getElementById('incorporar-fecha').value = hoy;

        // Mostrar modal
        document.getElementById('incorporar-step1').style.display = 'block';
        document.getElementById('incorporar-step2').style.display = 'none';
        document.getElementById('modal-incorporar').style.display = 'block';

    } catch (error) {
        hideLoading();
        console.error('Error:', error);
        showToast('Error al verificar cuadrantes', 'error');
    }
}

// Event listeners para modal de incorporación
document.getElementById('btn-analizar-incorporacion').addEventListener('click', analizarIncorporacion);
document.getElementById('btn-confirmar-incorporacion').addEventListener('click', confirmarIncorporacion);
document.getElementById('btn-volver-incorporar').addEventListener('click', () => {
    document.getElementById('incorporar-step1').style.display = 'block';
    document.getElementById('incorporar-step2').style.display = 'none';
});
document.getElementById('btn-cancelar-incorporar').addEventListener('click', () => {
    document.getElementById('modal-incorporar').style.display = 'none';
});
document.getElementById('btn-cancelar-incorporar2').addEventListener('click', () => {
    document.getElementById('modal-incorporar').style.display = 'none';
});

async function analizarIncorporacion() {
    cuadranteIncorporarId = document.getElementById('incorporar-cuadrante').value;
    fechaIncorporarDesde = document.getElementById('incorporar-fecha').value;

    if (!cuadranteIncorporarId || !fechaIncorporarDesde) {
        showToast('Selecciona un cuadrante y una fecha', 'warning');
        return;
    }

    showLoading('Analizando impacto...');

    try {
        const response = await fetch('api/incorporar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                persona_id: personaIncorporarId,
                cuadrante_id: cuadranteIncorporarId,
                fecha_desde: fechaIncorporarDesde,
                accion: 'analizar'
            })
        });

        const data = await response.json();
        hideLoading();

        if (!data.success) {
            showToast(data.message, 'error');
            return;
        }

        // Mostrar análisis
        mostrarAnalisisIncorporacion(data);

    } catch (error) {
        hideLoading();
        console.error('Error:', error);
        showToast('Error al analizar incorporación', 'error');
    }
}

function mostrarAnalisisIncorporacion(data) {
    const content = document.getElementById('incorporar-analisis-content');

    let html = `
        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <h3 style="color: #667eea; margin-bottom: 10px;">Cuadrante: ${data.cuadrante.nombre}</h3>
            <p><strong>Persona:</strong> ${data.persona.nombre}</p>
            <p><strong>Fecha de incorporación:</strong> ${formatearFecha(data.fecha_incorporacion)}</p>
            <p><strong>Semanas a regenerar:</strong> ${data.total_semanas_afectadas}</p>
        </div>
    `;

    // Verificar si hay personas de baja con sustituciones
    if (data.personas_de_baja && data.personas_de_baja.length > 0) {
        html += `
            <div style="background: #e7f3ff; padding: 15px; border-radius: 8px; margin-bottom: 15px; border-left: 4px solid #2196F3;">
                <h4 style="color: #0c5460; margin-bottom: 10px;">🏥 Personas de baja detectadas:</h4>
                <ul style="margin: 10px 0; padding-left: 20px; color: #0c5460;">
        `;
        data.personas_de_baja.forEach(p => {
            html += `<li>${p.nombre} (baja desde ${formatearFecha(p.fecha_baja)})</li>`;
        });
        html += `
                </ul>
                <div style="background: white; padding: 12px; border-radius: 5px; margin-top: 10px;">
                    <label style="display: flex; align-items: center; cursor: pointer; font-weight: 500;">
                        <input type="checkbox" id="incorporar-cubrir-baja" style="width: 18px; height: 18px; margin-right: 10px; cursor: pointer;">
                        <span>¿Incorporar para cubrir la baja?</span>
                    </label>
                    <div id="incorporar-explicacion" style="margin-top: 8px; padding: 8px; background: #f8f9fa; border-radius: 4px; font-size: 0.9em; color: #666;">
                        <strong>No marcado:</strong> Se regenerarán todos los turnos incluyendo a ${data.persona.nombre}. Las sustituciones actuales continuarán hasta la fecha de incorporación.
                    </div>
                </div>
            </div>
        `;
    }

    if (data.semanas_mantenidas.length > 0) {
        html += `
            <div style="background: #d4edda; padding: 15px; border-radius: 8px; margin-bottom: 15px; border-left: 4px solid #28a745;">
                <h4 style="color: #155724; margin-bottom: 10px;">✓ Se mantendrán intactas (${data.semanas_mantenidas.length} semanas):</h4>
                <ul style="margin: 0; padding-left: 20px; color: #155724;">
        `;
        data.semanas_mantenidas.forEach(s => {
            html += `<li>Semana ${formatearFecha(s.lunes)} - ${formatearFecha(s.viernes)}</li>`;
        });
        html += `
                </ul>
            </div>
        `;
    }

    html += `
        <div style="background: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #ffc107;">
            <h4 style="color: #856404; margin-bottom: 10px;">🔄 Se regenerarán incluyendo a ${data.persona.nombre} (${data.semanas_regeneradas.length} semanas):</h4>
            <ul style="margin: 0; padding-left: 20px; color: #856404;">
    `;
    data.semanas_regeneradas.forEach(s => {
        html += `<li>Semana ${formatearFecha(s.lunes)} - ${formatearFecha(s.viernes)}</li>`;
    });
    html += `
            </ul>
        </div>
    `;

    html += `
        <div style="background: #fee; padding: 15px; border-radius: 8px; margin-top: 15px; border-left: 4px solid #dc3545;">
            <strong style="color: #dc3545;">⚠️ Advertencia:</strong>
            <p style="color: #721c24; margin: 5px 0 0 0;">
                Las asignaciones de las ${data.total_semanas_afectadas} semanas futuras se borrarán y regenerarán automáticamente.
                Esta acción NO se puede deshacer.
            </p>
        </div>
    `;

    content.innerHTML = html;

    // Agregar evento para cambiar explicación
    const checkbox = document.getElementById('incorporar-cubrir-baja');
    if (checkbox) {
        checkbox.addEventListener('change', function() {
            const explicacion = document.getElementById('incorporar-explicacion');
            if (this.checked) {
                explicacion.innerHTML = `<strong>Marcado:</strong> ${data.persona.nombre} adoptará el horario de la persona de baja. Se eliminarán las sustituciones y el resto volverá a sus turnos originales.`;
                explicacion.style.background = '#d4edda';
                explicacion.style.color = '#155724';
            } else {
                explicacion.innerHTML = `<strong>No marcado:</strong> Se regenerarán todos los turnos incluyendo a ${data.persona.nombre}. Las sustituciones actuales continuarán hasta la fecha de incorporación.`;
                explicacion.style.background = '#f8f9fa';
                explicacion.style.color = '#666';
            }
        });
    }

    // Mostrar step 2
    document.getElementById('incorporar-step1').style.display = 'none';
    document.getElementById('incorporar-step2').style.display = 'block';
}

async function confirmarIncorporacion() {
    // Leer si se está cubriendo baja
    const checkbox = document.getElementById('incorporar-cubrir-baja');
    const cubrirBaja = checkbox ? checkbox.checked : false;

    const mensaje = cubrirBaja
        ? '¿Confirmas cubrir la baja?\n\nLa persona incorporada adoptará el horario de la persona de baja y se eliminarán las sustituciones.\n\nEsta acción NO se puede deshacer.'
        : '¿Confirmas la regeneración del cuadrante?\n\nEsta acción NO se puede deshacer.';

    if (!confirm(mensaje)) {
        return;
    }

    showLoading(cubrirBaja ? 'Cubriendo baja...' : 'Regenerando cuadrante...');

    document.getElementById('modal-incorporar').style.display = 'none';

    try {
        const response = await fetch('api/incorporar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                persona_id: personaIncorporarId,
                cuadrante_id: cuadranteIncorporarId,
                fecha_desde: fechaIncorporarDesde,
                accion: 'regenerar',
                cubrir_baja: cubrirBaja
            })
        });

        const data = await response.json();
        hideLoading();

        if (data.success) {
            showToast(data.message, 'success', 6000);
            cargarPersonas();

            // Si el cuadrante regenerado es el actual, recargarlo
            if (cuadranteActual && cuadranteActual.id == cuadranteIncorporarId) {
                cargarCuadrante();
            }
        } else {
            showToast(data.message, 'error');
        }

    } catch (error) {
        hideLoading();
        console.error('Error:', error);
        showToast('Error al incorporar persona', 'error');
    }
}