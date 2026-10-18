class FileUploader {
    constructor(options = {}) {
        this.apiEndpoint = 'http://192.168.1.62/ftpconnect/api/v1/upload.php';
        this.maxSize = options.maxSize || 10 * 1024 * 1024; // 10MB
        this.allowedTypes = options.allowedTypes || ['pdf', 'doc', 'docx', 'jpg', 'png'];
    }

    async uploadFile(file, customName = '') {
        try {
            this.validateFile(file);
            
            const formData = new FormData();
            formData.append('file', file);
            if (customName) {
                formData.append('filename', customName);
            }

            // Remove Content-Type header to let browser set it correctly for multipart/form-data
            const response = await fetch(this.apiEndpoint, {
                method: 'POST',
                body: formData,
                mode: 'cors',
                credentials: 'include',
                headers: {
                    'Accept': 'application/json'
                }
            });

            // Add specific error handling for CORS issues
            if (response.status === 500) {
                throw new Error('Server error: CORS configuration issue or file upload failed');
            }

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error || 'Upload failed');
            }

            return result.fileUrl;

        } catch (error) {
            if (error.name === 'TypeError' && error.message.includes('fetch')) {
                throw new Error(`Network error: Origin ${window.location.origin} may not be allowed`);
            }
            throw error;
        }
    }

    validateFile(file) {
        const ext = file.name.split('.').pop().toLowerCase();
        
        if (!this.allowedTypes.includes(ext)) {
            throw new Error('File type not allowed');
        }
        
        if (file.size > this.maxSize) {
            throw new Error('File size exceeds limit');
        }
    }
}
