module.exports = {
    // Admin UI HTTP Authentication
    adminAuth: {
        type: "credentials",
        users: [
            {{USERS_ARRAY}}
        ]
    },

    // Credential Secret
    credentialSecret: "{{CREDENTIAL_SECRET}}",

    // Editor Settings
    editorTheme: {
        projects: {
            enabled: false
        }
    },

    // Other settings
    logging: {
        console: {
            level: "info"
        }
    }
};

