"""Prepara las vistas individuales del maestro para la extensión Plan UML."""

from hashlib import sha256
from pathlib import Path
import re


def main() -> None:
    directory = Path(__file__).resolve().parent
    source = (directory / "CRM_FLUJO_COMPLETO.puml").read_text(encoding="utf-8")
    blocks = list(
        re.finditer(
            r"^@startuml(?:[ \t]+([^\n]+))?\n.*?^@enduml[ \t]*$",
            source,
            re.MULTILINE | re.DOTALL,
        )
    )
    if not blocks:
        raise ValueError("El archivo maestro no contiene diagramas PlantUML.")

    destination = directory / "vistas"
    destination.mkdir(exist_ok=True)
    for index, block in enumerate(blocks):
        name = (block.group(1) or "crm_00_panorama").strip()
        if not re.fullmatch(r"[a-z0-9_]+", name):
            raise ValueError(f"Nombre de vista no permitido: {name}")
        fingerprint = sha256(block.group().encode("utf-8")).hexdigest()[:12]
        content = (
            "' Vista generada por generar_vistas.py. Editar el archivo maestro.\n"
            f"' Versión de origen: {fingerprint}\n"
            "@startuml\n"
            f"!include ../CRM_FLUJO_COMPLETO.puml!{index}\n"
            "@enduml\n"
        )
        path = destination / f"{name}.puml"
        if not path.exists() or path.read_text(encoding="utf-8") != content:
            path.write_text(content, encoding="utf-8")

    print(f"{len(blocks)} vistas listas en {destination}")


if __name__ == "__main__":
    main()
