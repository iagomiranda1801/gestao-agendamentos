# Especificação — Prontuário compartilhado por especialidade

**Status:** implementada (MVP)  
**Versão:** 1.0  
**Data:** 08/09/2026  
**Produto:** Agendaqui

## 1. Objetivo

Permitir que clínicas e profissionais autônomos (psicologia, medicina, nutrição e afins) usem o módulo **Prontuário clínico**, com uma ficha por paciente **dentro da empresa**. Odontologia continua com odontograma e planos por dente; as demais especialidades usam anamnese, evoluções, alertas e anexos.

## 2. Decisões de produto

1. O prontuário **não atravessa empresas**. Paciente da empresa A nunca aparece na B (`clients.company_id`).
2. Na **mesma** empresa, psicólogo, médico e nutricionista compartilham a **mesma ficha** do paciente. Cada um registra o que é da sua especialidade.
3. Não há três prontuários isolados nem três perfis de cadastro novos. **Clínica** e **Profissional autônomo** ligam o módulo Prontuário. Odontológica segue como hoje.
4. `Client` continua sendo a pessoa. Número de prontuário, nome social, sexo e endereço reutilizam `dental_patient_profiles` (perfil clínico do paciente).
5. Anamnese, evolução, alerta, anexo e auditoria reutilizam as tabelas clínicas atuais. Questionário e campos da evolução mudam pela especialidade.
6. Receita e plano alimentar nesta fase são **arquivos anexados**, sem validade de e-receita e sem montador de cardápio.

## 3. Isolamento (tenant)

| Situação | Compartilha ficha? |
|---|---|
| Dois profissionais na mesma empresa | Sim |
| Empresas diferentes | Não |
| Superadmin de plataforma | Não vê o prontuário da empresa como se fosse da outra |

## 4. Escopo do MVP

- Ficha do paciente (número de prontuário, dados, alertas)
- Anamnese com questionário da especialidade do profissional (`psychology`, `medicine`, `nutrition`; odonto permanece o questionário atual)
- Evoluções: queixa, avaliação, conduta; **sem** dentes/anestesia fora da odonto
- Alertas derivados da anamnese
- Anexos: radiografia, foto, exame, receita, atestado, termo, **plano alimentar**, geral
- Vocabulário **Paciente** quando o módulo clínico está ligado
- Papel `dentist` no banco; rótulo **Profissional clínico** fora da odonto (lá continua **Dentista**)
- Especialidade clínica no cadastro do profissional
- Configurações de escopo de prontuário (todos vs só os meus) para qualquer empresa com o módulo

## 5. Fora do escopo desta fase

- E-receita (CFM/Anvisa)
- Portal do paciente
- TISS / convênio eletrônico
- Plano alimentar estruturado (refeições, macros)
- Odontograma e plano com dente/face fora do perfil odontológico
- Novos perfis de empresa (Clínica de nutrição, etc.)

## 6. Modelo

- `professionals.clinical_specialty`: `psychology` | `medicine` | `nutrition` | `dentistry` | null  
  Em clínica odontológica, o padrão do formulário é `dentistry`.
- Autorização clínica: módulo `clinical_records` (odontológica continua com o módulo implícito).
- Odontograma e planos odontológicos: somente `business_profile = dental_clinic`.

## 7. Critérios de aceite

1. Empresa `clinic` com módulo prontuário: admin abre a ficha do paciente.
2. Tentativa de acessar paciente de outra empresa: 403/404.
3. Anamnese de nutrição usa questionário diferente do odontológico.
4. Evolução em clínica não-odonto não exige dentes.
5. Anexo aceita tipo `meal_plan`.
6. Clínica odontológica existente continua com anamnese, evolução, odontograma e planos.
