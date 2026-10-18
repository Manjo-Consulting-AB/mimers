# Utvecklingsmiljön: samma PHP och Node som CI (.github/workflows/ci.yml).
# Aktiveras av direnv genom .envrc, eller för hand med `nix develop`.
{
  description = "Mimers – utvecklingsmiljö";

  inputs.nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";

  outputs = { self, nixpkgs }:
    let
      system = "x86_64-linux";
      pkgs = nixpkgs.legacyPackages.${system};

      # composer.json kräver ^8.4. Standardtilläggen täcker CI:s lista
      # (fileinfo, mbstring, pdo_sqlite, sqlite3, zip, pdo_mysql); redis
      # för att .env.example kör phpredis. Ingen xdebug – den gör sviten
      # långsam och behövs bara vid stegvis felsökning.
      php = pkgs.php84.buildEnv {
        extensions = { enabled, all }: enabled ++ [ all.redis ];
        extraConfig = "memory_limit = 1G";
      };
    in
    {
      devShells.${system}.default = pkgs.mkShell {
        packages = [
          php
          php.packages.composer
          pkgs.nodejs_22
          # CI kör skripten under .github/scripts/ med python3; jq läser
          # `gh --json` och sqlite3 testdatabasen (phpunit.xml kör SQLite).
          pkgs.python3
          pkgs.jq
          pkgs.sqlite
        ];
      };
    };
}
