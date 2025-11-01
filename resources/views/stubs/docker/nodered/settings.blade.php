module.exports = {
    // Admin UI HTTP Authentication
    adminAuth: {
        type: "credentials",
        users: [
@foreach($users as $user)
            {
                username: {!! json_encode($user['username'], JSON_UNESCAPED_SLASHES) !!},
                password: {!! json_encode($user['password'], JSON_UNESCAPED_SLASHES) !!},
                permissions: {!! json_encode($user['permissions'], JSON_UNESCAPED_SLASHES) !!}
            }@if(!$loop->last),@endif

@endforeach
        ]
    },

    // Credential Secret
    credentialSecret: {!! json_encode($credentialSecret, JSON_UNESCAPED_SLASHES) !!},

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
