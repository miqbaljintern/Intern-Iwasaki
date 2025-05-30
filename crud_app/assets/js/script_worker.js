
// Description: JavaScript logic for frontend CRUD worker interaction.

document.addEventListener('DOMContentLoaded', () => {
    // ==== DOM Elements ====
    const workerTableBody = document.getElementById('workerTableBody');
    const addWorkerBtn = document.getElementById('addWorkerBtn');
    const workerModal = document.getElementById('workerModal');
    const closeModalBtn = document.getElementById('closeModalBtn'); // Ensure this ID exists in the worker modal
    const cancelModalBtn = document.getElementById('cancelModalBtn'); // Ensure this ID exists in the worker modal
    const workerForm = document.getElementById('workerForm');
    const modalTitle = document.getElementById('modalTitle'); // Ensure this ID exists in the worker modal
    const saveWorkerBtn = document.getElementById('saveWorkerBtn');

    // Input fields
    const sWorkerInput = document.getElementById('s_worker');
    const userNameInput = document.getElementById('user_name');
    const sCorpNameInput = document.getElementById('s_corp_name');
    const sDepartmentInput = document.getElementById('s_department');
    const dtStartInput = document.getElementById('dt_start');
    const dtEndInput = document.getElementById('dt_end');
    const workerIdKeyInput = document.getElementById('workerIdKey'); // Hidden input for old PK during edit

    const notification = document.getElementById('notification');
    const notificationMessage = document.getElementById('notificationMessage');

    // ==== API URL ====
    const apiUrl = 'api/worker_handler.php';
    let currentEditWorkerId = null;

    // ==== Notification Function ====
    function showNotification(message, type = 'success') {
        notificationMessage.textContent = message;
        notification.classList.remove(
            'hidden', 'bg-green-100', 'text-green-700',
            'bg-red-100', 'text-red-700',
            'bg-yellow-100', 'text-yellow-700'
        );

        if (type === 'success') {
            notification.classList.add('bg-green-100', 'text-green-700');
        } else if (type === 'error') {
            notification.classList.add('bg-red-100', 'text-red-700');
        } else if (type === 'warning') {
            notification.classList.add('bg-yellow-100', 'text-yellow-700');
        }

        notification.classList.remove('hidden');
        setTimeout(() => {
            notification.classList.add('hidden');
        }, 5000);
    }

    // ==== Modal Functions ====
    function openModal(isEdit = false, worker = null) {
        workerForm.reset();
        sWorkerInput.disabled = false; // Enable by default for new entries

        if (isEdit && worker) {
            modalTitle.textContent = 'Edit Employee';
            sWorkerInput.value = worker.s_worker;
            userNameInput.value = worker.user_name;
            sCorpNameInput.value = worker.s_corp_name || '';
            sDepartmentInput.value = worker.s_department || '';
            dtStartInput.value = worker.dt_start || '';
            dtEndInput.value = worker.dt_end || '';

            workerIdKeyInput.value = worker.s_worker; // Store the original key for PUT request
            currentEditWorkerId = worker.s_worker;
            // sWorkerInput.disabled = true; // Optional: disable PK editing
        } else {
            modalTitle.textContent = 'Add New Employee';
            workerIdKeyInput.value = '';
            currentEditWorkerId = null;
        }

        workerModal.classList.remove('hidden');
        setTimeout(() => {
            workerModal.querySelector('.modal-content').classList.remove('scale-95', 'opacity-0');
            workerModal.querySelector('.modal-content').classList.add('scale-100', 'opacity-100');
        }, 50);
        workerModal.classList.add('modal-active');
    }

    function closeModal() {
        workerModal.querySelector('.modal-content').classList.add('scale-95', 'opacity-0');
        workerModal.querySelector('.modal-content').classList.remove('scale-100', 'opacity-100');
        setTimeout(() => {
            workerModal.classList.add('hidden');
            workerModal.classList.remove('modal-active');
        }, 250);
    }

    // ==== Fetch & Render Workers ====
    async function fetchWorkers() {
        try {
            const response = await fetch(apiUrl);
            if (!response.ok) {
                const errorData = await response.json().catch(() => ({ message: "Failed to fetch employee data." }));
                throw new Error(errorData.message || `HTTP error! status: ${response.status}`);
            }
            const workers = await response.json();
            renderWorkers(workers);
        } catch (error) {
            console.error('Error fetching workers:', error);
            workerTableBody.innerHTML = `<tr><td colspan="7" class="text-center p-5 text-red-500">Failed to load data: ${error.message}</td></tr>`;
            showNotification(`Failed to load data: ${error.message}`, 'error');
        }
    }

    function renderWorkers(workers) {
        workerTableBody.innerHTML = '';

        if (!Array.isArray(workers) || workers.length === 0) {
            workerTableBody.innerHTML = '<tr><td colspan="7" class="text-center p-5 text-gray-500">No employee data available.</td></tr>';
            return;
        }

        workers.forEach(worker => {
            const row = workerTableBody.insertRow();
            row.classList.add('border-b', 'border-gray-200', 'hover:bg-gray-100');

            row.insertCell().textContent = worker.s_worker;
            row.insertCell().textContent = worker.user_name;
            row.insertCell().textContent = worker.s_corp_name || '-';
            row.insertCell().textContent = worker.s_department || '-';
            row.insertCell().textContent = worker.dt_start ? new Date(worker.dt_start + 'T00:00:00').toLocaleDateString('en-US') : '-'; // Ensure proper date display
            row.insertCell().textContent = worker.dt_end ? new Date(worker.dt_end + 'T00:00:00').toLocaleDateString('en-US') : '-'; // Ensure proper date display


            const actionsCell = row.insertCell();
            actionsCell.classList.add('px-5', 'py-3', 'text-sm', 'text-center');

            const editButton = document.createElement('button');
            editButton.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-500 hover:text-blue-700 inline-block" viewBox="0 0 20 20" fill="currentColor"><path d="M17.414 2.586a2 2 0 00-2.828 0L7 10.172V13h2.828l7.586-7.586a2 2 0 000-2.828zM5 12V7.172l2.586-2.586a2 2 0 012.828 0L18 12.172V15a2 2 0 01-2 2H5a2 2 0 01-2-2v-3z" /></svg>`;
            editButton.title = "Edit";
            editButton.classList.add('mr-2', 'focus:outline-none');
            editButton.onclick = () => openModal(true, worker);
            actionsCell.appendChild(editButton);

            const deleteButton = document.createElement('button');
            deleteButton.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-red-500 hover:text-red-700 inline-block" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" /></svg>`;
            deleteButton.title = "Delete";
            deleteButton.classList.add('focus:outline-none');
            deleteButton.onclick = () => deleteWorker(worker.s_worker);
            actionsCell.appendChild(deleteButton);
        });
    }

    // ==== Delete Worker ====
    async function deleteWorker(workerId) {
        if (!confirm(`Are you sure you want to delete employee with ID: ${workerId}?`)) {
            return;
        }

        try {
            const response = await fetch(`${apiUrl}?s_worker=${encodeURIComponent(workerId)}`, {
                method: 'DELETE',
            });
            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.message || `HTTP error! status: ${response.status}`);
            }
            showNotification(result.message || 'Employee successfully deleted.', 'success');
            fetchWorkers();
        } catch (error) {
            showNotification(`Failed to delete employee: ${error.message}`, 'error');
            console.error('Delete error:', error);
        }
    }


    // ==== Form Submit Handler ====
    if (workerForm) {
        workerForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            saveWorkerBtn.disabled = true;
            saveWorkerBtn.textContent = 'Saving...';

            const s_worker = sWorkerInput.value.trim();
            const user_name = userNameInput.value.trim();
            const s_corp_name = sCorpNameInput.value.trim() || null;
            const s_department = sDepartmentInput.value.trim() || null;
            const dt_start = dtStartInput.value || null;
            const dt_end = dtEndInput.value || null;
            const old_s_worker_key = workerIdKeyInput.value; // Get the original key

            if (!s_worker || !user_name) {
                showNotification('Employee ID and Employee Name cannot be empty.', 'error');
                saveWorkerBtn.disabled = false;
                saveWorkerBtn.textContent = 'Save';
                return;
            }

            let url = apiUrl;
            let method = 'POST';
            let bodyData = { s_worker, user_name, s_corp_name, s_department, dt_start, dt_end };

            if (currentEditWorkerId) { // If this is an edit
                method = 'PUT';
                // The key for WHERE clause is passed in URL. s_worker_key in body is for backend to know original PK if it's changed.
                url = `${apiUrl}?s_worker=${encodeURIComponent(old_s_worker_key)}`;
                bodyData.s_worker_key = old_s_worker_key; // Send the original key in the body as well, for clarity or complex PK changes
            }

            try {
                const response = await fetch(url, {
                    method: method,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(bodyData),
                });

                const result = await response.json();

                if (!response.ok) {
                    throw new Error(result.message || `HTTP error! status: ${response.status}`);
                }

                showNotification(result.message || (currentEditWorkerId ? 'Employee successfully updated.' : 'Employee successfully added.'), 'success');
                closeModal();
                fetchWorkers();
            } catch (error) {
                showNotification(`Failed to save data: ${error.message}`, 'error');
                console.error('Save error:', error);
            } finally {
                saveWorkerBtn.disabled = false;
                saveWorkerBtn.textContent = 'Save';
            }
        });
    }

    // ==== Event Listeners ====
    if (addWorkerBtn) addWorkerBtn.addEventListener('click', () => openModal());
    if (closeModalBtn) closeModalBtn.addEventListener('click', closeModal);
    if (cancelModalBtn) cancelModalBtn.addEventListener('click', closeModal);

    if (workerModal) {
        workerModal.addEventListener('click', (event) => {
            if (event.target === workerModal) closeModal(); // Click outside modal content to close
        });
    }
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && workerModal.classList.contains('modal-active')) {
            closeModal();
        }
    });


    // ==== Initial Fetch ====
    fetchWorkers();
});